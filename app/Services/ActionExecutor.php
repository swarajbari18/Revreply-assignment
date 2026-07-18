<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectedAccountStatus;
use App\Enums\WorkflowStatus;
use App\Models\AuditLog;
use App\Models\Classification;
use App\Models\Workflow;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Google\Service\Exception;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft;
use Google\Service\Gmail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ActionExecutor
{
    public function __construct(
        private readonly Client $client,
        private readonly GmailTokenService $tokenService
    ) {}

    /**
     * Execute the decision made by the Decision Engine.
     */
    public function execute(Workflow $workflow, Classification $classification, string $decision): void
    {
        $account = $workflow->connectedAccount;

        // 1. Authenticate Gmail Client
        try {
            $accessToken = $this->tokenService->getValidAccessToken($account);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'permanently revoked') || str_contains($e->getMessage(), 'no refresh token')) {
                $this->handleAuthRevocation($workflow, $e->getMessage());

                return;
            }
            throw $e;
        }

        $this->client->setAccessToken(['access_token' => $accessToken]);
        $gmailService = new Gmail($this->client);

        try {
            switch ($decision) {
                case 'Ignore':
                    $workflow->update([
                        'status' => WorkflowStatus::Ignored,
                    ]);
                    AuditLog::create([
                        'workflow_id' => $workflow->id,
                        'correlation_id' => $workflow->correlation_id,
                        'event' => 'workflow_ignored',
                        'metadata' => ['reason' => 'decision_engine_ignore'],
                    ]);
                    break;

                case 'Escalate':
                    $workflow->update([
                        'status' => WorkflowStatus::Failed,
                        'failure_reason' => 'escalated_for_manual_review',
                    ]);
                    AuditLog::create([
                        'workflow_id' => $workflow->id,
                        'correlation_id' => $workflow->correlation_id,
                        'event' => 'workflow_escalated',
                        'metadata' => ['reason' => 'decision_engine_escalate'],
                    ]);
                    break;

                case 'AutoReply':
                    $this->executeAutoReply($workflow, $classification, $gmailService);
                    break;

                case 'Draft':
                    $this->executeCreateDraft($workflow, $classification, $gmailService);
                    break;

                default:
                    Log::error("Unknown decision: {$decision} for workflow {$workflow->id}");
                    break;
            }
        } catch (Exception $e) {
            if ($e->getCode() === 401 || str_contains($e->getMessage(), 'invalid_grant')) {
                $this->handleAuthRevocation($workflow, $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    /**
     * Perform stale context check and reply automatically on Gmail thread.
     */
    private function executeAutoReply(Workflow $workflow, Classification $classification, Gmail $gmailService): void
    {
        // Stale Context Check: fetch latest message from Gmail thread
        $thread = $gmailService->users_threads->get('me', $workflow->thread_id, ['format' => 'minimal']);
        $messages = $thread->getMessages();
        $latestGmailMessage = end($messages);

        if ($latestGmailMessage && $latestGmailMessage->getId() !== $workflow->latest_message_id) {
            Log::info("Aborting auto-reply: thread {$workflow->thread_id} context is stale. Latest message on Gmail is {$latestGmailMessage->getId()} but workflow has {$workflow->latest_message_id}.");
            $workflow->update([
                'status' => WorkflowStatus::Ignored,
            ]);
            AuditLog::create([
                'workflow_id' => $workflow->id,
                'correlation_id' => $workflow->correlation_id,
                'event' => 'execution_aborted_stale_context',
                'metadata' => [
                    'gmail_latest_message_id' => $latestGmailMessage->getId(),
                    'workflow_latest_message_id' => $workflow->latest_message_id,
                ],
            ]);

            return;
        }

        // Fetch headers to build threaded response
        $latestMessageDetail = $gmailService->users_messages->get('me', $workflow->latest_message_id, [
            'format' => 'metadata',
            'metadataHeaders' => ['Message-ID', 'References', 'Subject', 'From'],
        ]);

        $headersInfo = $this->extractHeaders($latestMessageDetail);

        $rawMime = $this->buildRawMessage(
            $headersInfo['from'],
            $headersInfo['subject'],
            $classification->generated_draft,
            $headersInfo['messageId'],
            $headersInfo['references']
        );

        $msgObj = new Message;
        $msgObj->setRaw($rawMime);
        $msgObj->setThreadId($workflow->thread_id);

        $gmailService->users_messages->send('me', $msgObj);

        $workflow->update([
            'status' => WorkflowStatus::Sent,
            'completed_at' => now(),
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'email_sent',
            'metadata' => [
                'recipient' => $headersInfo['from'],
                'subject' => $headersInfo['subject'],
            ],
        ]);
    }

    /**
     * Perform stale context check and create a draft response on Gmail thread.
     */
    private function executeCreateDraft(Workflow $workflow, Classification $classification, Gmail $gmailService): void
    {
        // Stale Context Check
        $thread = $gmailService->users_threads->get('me', $workflow->thread_id, ['format' => 'minimal']);
        $messages = $thread->getMessages();
        $latestGmailMessage = end($messages);

        if ($latestGmailMessage && $latestGmailMessage->getId() !== $workflow->latest_message_id) {
            Log::info("Aborting draft creation: thread {$workflow->thread_id} context is stale.");
            $workflow->update([
                'status' => WorkflowStatus::Ignored,
            ]);
            AuditLog::create([
                'workflow_id' => $workflow->id,
                'correlation_id' => $workflow->correlation_id,
                'event' => 'execution_aborted_stale_context',
                'metadata' => [
                    'gmail_latest_message_id' => $latestGmailMessage->getId(),
                    'workflow_latest_message_id' => $workflow->latest_message_id,
                ],
            ]);

            return;
        }

        // Fetch headers
        $latestMessageDetail = $gmailService->users_messages->get('me', $workflow->latest_message_id, [
            'format' => 'metadata',
            'metadataHeaders' => ['Message-ID', 'References', 'Subject', 'From'],
        ]);

        $headersInfo = $this->extractHeaders($latestMessageDetail);

        $rawMime = $this->buildRawMessage(
            $headersInfo['from'],
            $headersInfo['subject'],
            $classification->generated_draft,
            $headersInfo['messageId'],
            $headersInfo['references']
        );

        $msgObj = new Message;
        $msgObj->setRaw($rawMime);
        $msgObj->setThreadId($workflow->thread_id);

        $draftObj = new Draft;
        $draftObj->setMessage($msgObj);

        $createdDraft = $gmailService->users_drafts->create('me', $draftObj);

        $workflow->update([
            'status' => WorkflowStatus::DraftCreated,
            'draft_id' => $createdDraft->getId(),
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'draft_created',
            'metadata' => [
                'draft_id' => $createdDraft->getId(),
                'recipient' => $headersInfo['from'],
            ],
        ]);
    }

    /**
     * Handle permanent OAuth revocation by disconnecting account.
     */
    private function handleAuthRevocation(Workflow $workflow, string $errorMessage): void
    {
        $account = $workflow->connectedAccount;
        Log::error("Permanent auth revocation detected for connected account {$account->id}: {$errorMessage}");

        $account->update([
            'status' => ConnectedAccountStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
        ]);

        Cache::forget('ingestion_account:'.$account->gmail_email);
        Cache::forget("gmail_token_{$account->id}");

        $workflow->update([
            'status' => WorkflowStatus::Failed,
            'failure_reason' => 'gmail_auth_revoked',
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'gmail_auth_revoked',
            'metadata' => [
                'error' => $errorMessage,
            ],
        ]);
    }

    /**
     * Extract key headers from Gmail Message detail object.
     */
    private function extractHeaders(Message $message): array
    {
        $payload = $message->getPayload();
        $headers = $payload ? $payload->getHeaders() : [];
        $messageId = null;
        $references = '';
        $subject = '';
        $from = '';

        foreach ($headers as $header) {
            $name = strtolower($header->getName());
            if ($name === 'message-id') {
                $messageId = $header->getValue();
            } elseif ($name === 'references') {
                $references = $header->getValue();
            } elseif ($name === 'subject') {
                $subject = $header->getValue();
            } elseif ($name === 'from') {
                $from = $header->getValue();
            }
        }

        if (empty($messageId)) {
            $messageId = '<'.$message->getId().'@gmail.com>';
        }

        $newReferences = trim($references.' '.$messageId);

        if (! empty($subject) && ! str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: '.$subject;
        }

        return [
            'messageId' => $messageId,
            'references' => $newReferences,
            'subject' => $subject,
            'from' => $from,
        ];
    }

    /**
     * Build base64url encoded raw email MIME payload.
     */
    private function buildRawMessage(string $to, string $subject, string $body, string $inReplyTo, string $references): string
    {
        $rawMessage = "To: {$to}\r\n";
        $rawMessage .= "Subject: {$subject}\r\n";
        $rawMessage .= "In-Reply-To: {$inReplyTo}\r\n";
        $rawMessage .= "References: {$references}\r\n";
        $rawMessage .= "Auto-Submitted: auto-replied\r\n";
        $rawMessage .= "MIME-Version: 1.0\r\n";
        $rawMessage .= "Content-Type: text/plain; charset=utf-8\r\n";
        $rawMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $rawMessage .= base64_encode($body);

        return strtr(base64_encode($rawMessage), '+/', '-_');
    }
}
