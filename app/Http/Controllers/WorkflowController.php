<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WorkflowStatus;
use App\Models\AuditLog;
use App\Models\Classification;
use App\Models\Workflow;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly Client $client,
        private readonly GmailTokenService $tokenService
    ) {}

    /**
     * List workflows for a connected account.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => 'required|integer',
            'status' => 'nullable|string',
        ]);

        $accountId = (int) $request->input('account_id');
        $status = $request->input('status');

        $query = Workflow::with('classification')
            ->where('connected_account_id', $accountId);

        if ($status) {
            $query->where('status', $status);
        }

        $workflows = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($workflows);
    }

    /**
     * Show detailed workflow info along with the full message thread fetched from Gmail.
     */
    public function show(int $id): JsonResponse
    {
        $workflow = Workflow::with(['classification', 'auditLogs' => function ($q) {
            $q->orderBy('created_at', 'asc');
        }])->findOrFail($id);

        $account = $workflow->connectedAccount;

        // Authenticate Gmail client
        try {
            $accessToken = $this->tokenService->getValidAccessToken($account);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Authentication failed: '.$e->getMessage()], 401);
        }

        $this->client->setAccessToken(['access_token' => $accessToken]);
        $gmailService = new Gmail($this->client);

        $messages = [];
        try {
            // Fetch thread history from Gmail
            $thread = $gmailService->users_threads->get('me', $workflow->thread_id);
            $gmailMessages = $thread->getMessages();

            foreach ($gmailMessages as $message) {
                $msgPayload = $message->getPayload();
                if (! $msgPayload) {
                    continue;
                }

                $headers = $msgPayload->getHeaders();
                $parsedHeaders = [];
                foreach ($headers as $header) {
                    $parsedHeaders[strtolower($header->getName())] = $header->getValue();
                }

                $bodyParsed = $this->parseMessageBody($msgPayload);
                $body = $bodyParsed['text'];
                if ($body === null && $bodyParsed['html'] !== null) {
                    $body = strip_tags($bodyParsed['html']);
                }
                $body = $body ?? '';

                $messages[] = [
                    'id' => $message->getId(),
                    'role' => $this->determineRole($parsedHeaders, $account->gmail_email),
                    'body' => $body,
                    'timestamp' => $this->formatTimestamp($parsedHeaders['date'] ?? null, (string) $message->getInternalDate()),
                    'from' => $parsedHeaders['from'] ?? '',
                    'to' => $parsedHeaders['to'] ?? '',
                    'subject' => $parsedHeaders['subject'] ?? '',
                ];
            }
        } catch (\Google\Service\Exception $e) {
            Log::error("Failed to fetch Gmail thread {$workflow->thread_id}: ".$e->getMessage());
            // Fallback: return workflow metadata only if thread cannot be fetched
        }

        return response()->json([
            'workflow' => $workflow,
            'messages' => $messages,
        ]);
    }

    /**
     * Approve and send the generated draft as is.
     */
    public function approve(int $id): JsonResponse
    {
        $workflow = Workflow::with('classification')->findOrFail($id);

        if ($workflow->status !== WorkflowStatus::DraftCreated || empty($workflow->draft_id)) {
            return response()->json(['error' => 'Workflow does not contain an active draft.'], 400);
        }

        $account = $workflow->connectedAccount;

        try {
            $accessToken = $this->tokenService->getValidAccessToken($account);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Authentication failed: '.$e->getMessage()], 401);
        }

        $this->client->setAccessToken(['access_token' => $accessToken]);
        $gmailService = new Gmail($this->client);

        try {
            // Get draft from Gmail to check for user edits
            $draft = $gmailService->users_drafts->get('me', $workflow->draft_id);
            $draftMsg = $draft->getMessage();
            $gmailBodyText = '';

            if ($draftMsg && $draftMsg->getPayload()) {
                $bodyParsed = $this->parseMessageBody($draftMsg->getPayload());
                $gmailBodyText = $bodyParsed['text'] ?? '';
            }

            // Clean bodies to remove carriage returns for comparison
            $cleanGmailBody = trim(str_replace("\r\n", "\n", $gmailBodyText));
            $cleanDatabaseBody = trim(str_replace("\r\n", "\n", $workflow->classification->generated_draft ?? ''));

            $isEdited = ($cleanGmailBody !== $cleanDatabaseBody && ! empty($cleanGmailBody));

            if ($isEdited) {
                Log::info("User edited draft {$workflow->draft_id} on Gmail directly.");
                $workflow->classification->update([
                    'generated_draft' => $gmailBodyText,
                ]);

                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'draft_sent_with_user_edits',
                    'metadata' => [
                        'draft_id' => $workflow->draft_id,
                    ],
                ]);
            } else {
                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'draft_approved_and_sent',
                    'metadata' => [
                        'draft_id' => $workflow->draft_id,
                    ],
                ]);
            }

            // Send draft
            $gmailService->users_drafts->send('me', $draft);

            $workflow->update([
                'status' => WorkflowStatus::Sent,
                'completed_at' => now(),
            ]);

            return response()->json(['success' => true]);

        } catch (\Google\Service\Exception $e) {
            Log::error("Failed to send draft for workflow {$workflow->id}: ".$e->getMessage());

            return response()->json(['error' => 'Gmail API error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Edit draft or write response from scratch, then send.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'body' => 'required|string',
        ]);

        $bodyText = $request->input('body');
        $workflow = Workflow::with('classification')->findOrFail($id);

        if (! in_array($workflow->status, [WorkflowStatus::DraftCreated, WorkflowStatus::Failed], true)) {
            return response()->json(['error' => 'Workflow is not in a customizable state.'], 400);
        }

        $account = $workflow->connectedAccount;

        try {
            $accessToken = $this->tokenService->getValidAccessToken($account);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Authentication failed: '.$e->getMessage()], 401);
        }

        $this->client->setAccessToken(['access_token' => $accessToken]);
        $gmailService = new Gmail($this->client);

        try {
            if (! empty($workflow->draft_id)) {
                // Fetch latest message to get thread headers
                $latestMessageDetail = $gmailService->users_messages->get('me', $workflow->latest_message_id, [
                    'format' => 'metadata',
                    'metadataHeaders' => ['Message-ID', 'References', 'Subject', 'From'],
                ]);
                $headersInfo = $this->extractHeaders($latestMessageDetail, $workflow->latest_message_id);

                $rawMime = $this->buildRawMessage(
                    $headersInfo['from'],
                    $headersInfo['subject'],
                    $bodyText,
                    $headersInfo['messageId'],
                    $headersInfo['references']
                );

                // Update the Gmail draft message body
                $msgObj = new Message;
                $msgObj->setRaw($rawMime);
                $msgObj->setThreadId($workflow->thread_id);

                $draftObj = new Draft;
                $draftObj->setId($workflow->draft_id);
                $draftObj->setMessage($msgObj);

                $gmailService->users_drafts->update('me', $workflow->draft_id, $draftObj);

                // Send the updated draft
                $gmailService->users_drafts->send('me', $draftObj);

                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'draft_edited_and_sent',
                    'metadata' => [
                        'draft_id' => $workflow->draft_id,
                    ],
                ]);
            } else {
                // Send direct message (e.g. escalated workflow without prior draft)
                $latestMessageDetail = $gmailService->users_messages->get('me', $workflow->latest_message_id, [
                    'format' => 'metadata',
                    'metadataHeaders' => ['Message-ID', 'References', 'Subject', 'From'],
                ]);
                $headersInfo = $this->extractHeaders($latestMessageDetail, $workflow->latest_message_id);

                $rawMime = $this->buildRawMessage(
                    $headersInfo['from'],
                    $headersInfo['subject'],
                    $bodyText,
                    $headersInfo['messageId'],
                    $headersInfo['references']
                );

                $msgObj = new Message;
                $msgObj->setRaw($rawMime);
                $msgObj->setThreadId($workflow->thread_id);

                $gmailService->users_messages->send('me', $msgObj);

                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'email_sent',
                    'metadata' => [
                        'recipient' => $headersInfo['from'],
                        'subject' => $headersInfo['subject'],
                        'mode' => 'manual_resolution',
                    ],
                ]);
            }

            // Sync database classification
            if ($workflow->classification) {
                $workflow->classification->update([
                    'generated_draft' => $bodyText,
                ]);
            }

            $workflow->update([
                'status' => WorkflowStatus::Sent,
                'completed_at' => now(),
            ]);

            return response()->json(['success' => true]);

        } catch (\Google\Service\Exception $e) {
            Log::error("Failed to send customized response for workflow {$workflow->id}: ".$e->getMessage());

            return response()->json(['error' => 'Gmail API error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Reject draft and delete from Gmail.
     */
    public function reject(int $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);
        $account = $workflow->connectedAccount;

        try {
            $accessToken = $this->tokenService->getValidAccessToken($account);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Authentication failed: '.$e->getMessage()], 401);
        }

        $this->client->setAccessToken(['access_token' => $accessToken]);
        $gmailService = new Gmail($this->client);

        try {
            if (! empty($workflow->draft_id)) {
                $gmailService->users_drafts->delete('me', $workflow->draft_id);
            }

            $workflow->update([
                'status' => WorkflowStatus::Ignored,
            ]);

            AuditLog::create([
                'workflow_id' => $workflow->id,
                'correlation_id' => $workflow->correlation_id,
                'event' => 'workflow_rejected',
                'metadata' => [
                    'draft_id' => $workflow->draft_id,
                ],
            ]);

            return response()->json(['success' => true]);

        } catch (\Google\Service\Exception $e) {
            Log::error("Failed to reject draft for workflow {$workflow->id}: ".$e->getMessage());

            return response()->json(['error' => 'Gmail API error: '.$e->getMessage()], 500);
        }
    }

    // Helper Methods:

    private function parseMessageBody(MessagePart $part): array
    {
        $mimeType = $part->getMimeType();
        $bodyData = $part->getBody() ? $part->getBody()->getData() : null;

        if ($mimeType === 'text/plain' && $bodyData !== null) {
            return [
                'text' => $this->decodeBase64Url($bodyData),
                'html' => null,
            ];
        }

        if ($mimeType === 'text/html' && $bodyData !== null) {
            return [
                'text' => null,
                'html' => $this->decodeBase64Url($bodyData),
            ];
        }

        $text = null;
        $html = null;
        $parts = $part->getParts();
        if (! empty($parts)) {
            foreach ($parts as $subPart) {
                $parsed = $this->parseMessageBody($subPart);
                if ($parsed['text'] !== null) {
                    $text = ($text ?? '').$parsed['text'];
                }
                if ($parsed['html'] !== null) {
                    $html = ($html ?? '').$parsed['html'];
                }
            }
        }

        return [
            'text' => $text,
            'html' => $html,
        ];
    }

    private function decodeBase64Url(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private function determineRole(array $headers, string $connectedEmail): string
    {
        $from = $headers['from'] ?? '';
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            $fromEmail = $matches[1];
        } else {
            $fromEmail = trim($from);
        }

        return strtolower($fromEmail) === strtolower($connectedEmail) ? 'assistant' : 'user';
    }

    private function formatTimestamp(?string $dateHeader, ?string $internalDate): string
    {
        if ($dateHeader) {
            try {
                return now()->parse($dateHeader)->toIso8601String();
            } catch (\Exception $e) {
                // ignore
            }
        }
        if ($internalDate) {
            try {
                return now()->setTimestamp((int) ($internalDate / 1000))->toIso8601String();
            } catch (\Exception $e) {
                // ignore
            }
        }

        return now()->toIso8601String();
    }

    private function extractHeaders(Message $message, string $fallbackMessageId): array
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
            $messageId = '<'.$fallbackMessageId.'@gmail.com>';
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
