<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\EmailContext;
use App\Enums\WorkflowStatus;
use App\Jobs\RenewGmailWatchJob;
use App\Models\AuditLog;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContextBuilderService
{
    public function __construct(
        private readonly Client $client,
        private readonly GmailTokenService $tokenService
    ) {}

    /**
     * Builds the email context for a given workflow.
     */
    public function build(int $workflowId, int $connectedAccountId): ?EmailContext
    {
        $lockKey = "workflow_lock:{$workflowId}";
        $lock = Cache::lock($lockKey, 300);

        if (! $lock->get()) {
            Log::debug('Workflow lock could not be acquired', ['workflow_id' => $workflowId]);

            return null;
        }

        try {
            $workflow = Workflow::findOrFail($workflowId);

            if (in_array($workflow->status, [WorkflowStatus::Ignored, WorkflowStatus::Failed], true)) {
                Log::debug('Workflow is in a terminal/ignored status, skipping context building', [
                    'workflow_id' => $workflowId,
                    'status' => $workflow->status->value,
                ]);

                return null;
            }

            $account = ConnectedAccount::findOrFail($connectedAccountId);

            // Check if we already completed context building
            $alreadyContextBuilt = in_array($workflow->status, [
                WorkflowStatus::ContextBuilt,
                WorkflowStatus::AiComplete,
                WorkflowStatus::DecisionComplete,
                WorkflowStatus::DraftCreated,
                WorkflowStatus::Sent,
            ], true);

            if (! $alreadyContextBuilt) {
                // Transition status to Processing
                $workflow->update([
                    'status' => WorkflowStatus::Processing,
                ]);

                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'workflow_processing_started',
                    'metadata' => [
                        'started_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            // Authenticate Google client
            try {
                $accessToken = $this->tokenService->getValidAccessToken($account);
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'permanently revoked') || str_contains($e->getMessage(), 'no refresh token')) {
                    Log::error('Permanent authentication failure during context building', [
                        'workflow_id' => $workflowId,
                        'account_id' => $connectedAccountId,
                        'error' => $e->getMessage(),
                    ]);

                    $workflow->update([
                        'status' => WorkflowStatus::Failed,
                    ]);

                    AuditLog::create([
                        'workflow_id' => $workflow->id,
                        'correlation_id' => $workflow->correlation_id,
                        'event' => 'workflow_failed_auth_revoked',
                        'metadata' => [
                            'error' => $e->getMessage(),
                        ],
                    ]);

                    return null;
                }
                throw $e;
            }

            $this->client->setAccessToken(['access_token' => $accessToken]);
            $gmailService = new Gmail($this->client);

            // Resolve thread_id and latest_message_id if null
            $threadId = $workflow->thread_id;
            $latestMessageId = $workflow->latest_message_id;

            if ($threadId === null || $latestMessageId === null) {
                try {
                    $notificationLog = AuditLog::where('workflow_id', $workflow->id)
                        ->where('event', 'notification_received')
                        ->first();

                    $startHistoryId = null;
                    if ($notificationLog && isset($notificationLog->metadata['previous_history_id'])) {
                        $startHistoryId = $notificationLog->metadata['previous_history_id'] !== null
                            ? (string) $notificationLog->metadata['previous_history_id']
                            : null;
                    }

                    if (empty($startHistoryId)) {
                        $startHistoryId = $account->last_history_id;
                    }

                    if ($notificationLog && isset($notificationLog->metadata['history_id'])) {
                        $incomingHistoryId = (string) $notificationLog->metadata['history_id'];
                        if ($startHistoryId === $incomingHistoryId) {
                            $startHistoryId = (string) ((int) $incomingHistoryId - 1);
                        }
                    }

                    if (empty($startHistoryId)) {
                        Log::warning('No last_history_id available to resolve thread', [
                            'workflow_id' => $workflow->id,
                            'account_id' => $account->id,
                        ]);

                        $profile = $gmailService->users->getProfile('me');
                        $currentHistoryId = (string) $profile->getHistoryId();

                        $account->forceFill([
                            'last_history_id' => $currentHistoryId,
                        ])->save();
                        $this->clearAccountCache($account->gmail_email);

                        RenewGmailWatchJob::dispatch($account);

                        $workflow->update([
                            'status' => WorkflowStatus::Failed,
                        ]);

                        AuditLog::create([
                            'workflow_id' => $workflow->id,
                            'correlation_id' => $workflow->correlation_id,
                            'event' => 'workflow_failed_history_empty',
                            'metadata' => [
                                'error' => 'No last_history_id available to resolve thread',
                            ],
                        ]);

                        return null;
                    }

                    $response = $gmailService->users_history->listUsersHistory('me', [
                        'startHistoryId' => $startHistoryId,
                    ]);
                    $histories = $response->getHistory();

                    if (! empty($histories)) {
                        foreach ($histories as $history) {
                            $messagesAdded = $history->getMessagesAdded();
                            if (! empty($messagesAdded)) {
                                foreach ($messagesAdded as $messageAdded) {
                                    $message = $messageAdded->getMessage();
                                    if ($message) {
                                        $threadId = $message->getThreadId();
                                        $latestMessageId = $message->getId();
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Google\Service\Exception $e) {
                    if ($e->getCode() === 401) {
                        $this->tokenService->forgetCachedToken($account);
                        $account->forceFill([
                            'token_expires_at' => now()->subMinutes(10), // Mark as expired
                        ])->save();
                        $this->clearAccountCache($account->gmail_email);
                        throw $e;
                    }

                    if ($e->getCode() === 404) {
                        Log::warning('Gmail History API returned 404 (history too old)', [
                            'workflow_id' => $workflow->id,
                            'account_id' => $account->id,
                        ]);

                        $profile = $gmailService->users->getProfile('me');
                        $currentHistoryId = (string) $profile->getHistoryId();

                        $account->forceFill([
                            'last_history_id' => $currentHistoryId,
                        ])->save();
                        $this->clearAccountCache($account->gmail_email);

                        RenewGmailWatchJob::dispatch($account);

                        $workflow->update([
                            'status' => WorkflowStatus::Failed,
                        ]);

                        AuditLog::create([
                            'workflow_id' => $workflow->id,
                            'correlation_id' => $workflow->correlation_id,
                            'event' => 'workflow_failed_history_stale',
                            'metadata' => [
                                'error' => 'History too stale, reset last_history_id',
                            ],
                        ]);

                        return null;
                    }
                    throw $e;
                }

                if ($threadId === null || $latestMessageId === null) {
                    Log::warning('Could not resolve thread_id or latest_message_id from History API', [
                        'workflow_id' => $workflow->id,
                        'account_id' => $account->id,
                    ]);

                    $profile = $gmailService->users->getProfile('me');
                    $currentHistoryId = (string) $profile->getHistoryId();

                    $account->forceFill([
                        'last_history_id' => $currentHistoryId,
                    ])->save();
                    $this->clearAccountCache($account->gmail_email);

                    RenewGmailWatchJob::dispatch($account);

                    $workflow->update([
                        'status' => WorkflowStatus::Failed,
                    ]);

                    AuditLog::create([
                        'workflow_id' => $workflow->id,
                        'correlation_id' => $workflow->correlation_id,
                        'event' => 'workflow_failed_history_empty',
                        'metadata' => [
                            'error' => 'No messages added found in history',
                        ],
                    ]);

                    return null;
                }

                // Write resolved IDs back to Workflow record
                $workflow->update([
                    'thread_id' => $threadId,
                    'latest_message_id' => $latestMessageId,
                ]);
            }

            // Fetch full thread
            try {
                $thread = $gmailService->users_threads->get('me', $threadId);
            } catch (\Google\Service\Exception $e) {
                if ($e->getCode() === 404) {
                    Log::info('Gmail thread not found (likely deleted or transient), skipping workflow', [
                        'workflow_id' => $workflow->id,
                        'thread_id' => $threadId,
                    ]);

                    $workflow->update([
                        'status' => WorkflowStatus::Ignored,
                    ]);

                    AuditLog::create([
                        'workflow_id' => $workflow->id,
                        'correlation_id' => $workflow->correlation_id,
                        'event' => 'workflow_ignored_thread_deleted',
                        'metadata' => [
                            'thread_id' => $threadId,
                            'error' => 'Requested entity was not found.',
                        ],
                    ]);

                    return null;
                }
                
                throw $e;
            }

            $gmailMessages = $thread->getMessages();

            if (empty($gmailMessages)) {
                throw new \RuntimeException("Gmail thread {$threadId} contains no messages.");
            }

            // Parse messages
            $messages = [];
            $attachments = [];
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
                    'role' => $this->determineRole($parsedHeaders, $account->gmail_email),
                    'body' => $body,
                    'timestamp' => $this->formatTimestamp($parsedHeaders['date'] ?? null, (string) $message->getInternalDate()),
                    'from' => $parsedHeaders['from'] ?? '',
                    'to' => $parsedHeaders['to'] ?? '',
                    'subject' => $parsedHeaders['subject'] ?? '',
                ];

                // Extract attachment IDs
                $this->extractAttachmentIds($msgPayload, $message->getId(), $attachments);
            }

            // Auto-reply loop protection: check the most recent message's headers
            $latestGmailMessage = end($gmailMessages);
            $latestPayload = $latestGmailMessage->getPayload();
            $latestHeaders = [];
            if ($latestPayload) {
                foreach ($latestPayload->getHeaders() as $header) {
                    $latestHeaders[strtolower($header->getName())] = $header->getValue();
                }
            }

            $autoSubmitted = $latestHeaders['auto-submitted'] ?? null;
            if ($autoSubmitted !== null && strtolower($autoSubmitted) !== 'no') {
                Log::info('Auto-reply loop detected, skipping workflow', [
                    'workflow_id' => $workflowId,
                    'auto_submitted' => $autoSubmitted,
                ]);

                $workflow->update([
                    'status' => WorkflowStatus::Ignored,
                ]);

                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'workflow_ignored_auto_submitted',
                    'metadata' => [
                        'auto_submitted' => $autoSubmitted,
                    ],
                ]);

                return null;
            }

            // Fetch plain text attachments
            $attachmentTexts = [];
            foreach ($attachments as $attachment) {
                if ($attachment['mimeType'] !== 'text/plain') {
                    continue;
                }

                try {
                    $attachmentObj = $gmailService->users_messages_attachments->get('me', $attachment['messageId'], $attachment['id']);
                    $data = $attachmentObj->getData();
                    if ($data) {
                        $attachmentTexts[] = $this->decodeBase64Url($data);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch attachment', [
                        'workflow_id' => $workflowId,
                        'message_id' => $attachment['messageId'],
                        'attachment_id' => $attachment['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fetch previous active drafts on the thread to avoid duplicate drafts and incorporate context
            $pendingDraftBody = null;
            $previousWorkflow = Workflow::where('thread_id', $threadId)
                ->where('id', '<', $workflow->id)
                ->where('status', WorkflowStatus::DraftCreated)
                ->whereNotNull('draft_id')
                ->first();

            if ($previousWorkflow) {
                try {
                    $draft = $gmailService->users_drafts->get('me', $previousWorkflow->draft_id);
                    $draftMsg = $draft->getMessage();
                    if ($draftMsg) {
                        $parsedDraftBody = $this->parseMessageBody($draftMsg->getPayload());
                        $draftText = $parsedDraftBody['text'];
                        if ($draftText === null && $parsedDraftBody['html'] !== null) {
                            $draftText = strip_tags($parsedDraftBody['html']);
                        }
                        $pendingDraftBody = $draftText ?? '';
                    }

                    // Delete the draft from Gmail
                    $gmailService->users_drafts->delete('me', $previousWorkflow->draft_id);

                    // Update previous workflow to Ignored
                    $previousWorkflow->update([
                        'status' => WorkflowStatus::Ignored,
                    ]);

                    AuditLog::create([
                        'workflow_id' => $previousWorkflow->id,
                        'correlation_id' => $previousWorkflow->correlation_id,
                        'event' => 'workflow_ignored',
                        'metadata' => [
                            'reason' => 'superceded_by_new_reply',
                            'new_workflow_id' => $workflow->id,
                        ],
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to handle previous pending draft during context building', [
                        'workflow_id' => $workflow->id,
                        'previous_workflow_id' => $previousWorkflow->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Load user preferences
            $preferences = $this->loadUserPreferences($account->user_id);

            // Assemble EmailContext DTO
            $context = new EmailContext(
                threadId: $threadId,
                latestMessageId: $latestMessageId,
                gmailEmail: $account->gmail_email,
                messages: $messages,
                attachmentTexts: $attachmentTexts,
                preferredTone: $preferences['preferredTone'],
                autoSendEnabled: $preferences['autoSendEnabled'],
                autoSendConfidenceThreshold: $preferences['autoSendConfidenceThreshold'],
                autoSendRiskThreshold: $preferences['autoSendRiskThreshold'],
                ignoredSenderPatterns: $preferences['ignoredSenderPatterns'],
                correlationId: $workflow->correlation_id,
                pendingDraftBody: $pendingDraftBody
            );

            if (! $alreadyContextBuilt) {
                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'context_built',
                    'metadata' => [
                        'message_count' => count($messages),
                        'attachment_count' => count($attachmentTexts),
                    ],
                ]);

                $workflow->update([
                    'status' => WorkflowStatus::ContextBuilt,
                ]);
            }

            return $context;

        } finally {
            $lock->release();
        }
    }

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

    private function extractAttachmentIds(MessagePart $part, string $messageId, array &$attachments): void
    {
        $filename = $part->getFilename();
        $body = $part->getBody();
        $attachmentId = $body ? $body->getAttachmentId() : null;

        if (! empty($filename) && ! empty($attachmentId)) {
            $attachments[] = [
                'id' => $attachmentId,
                'messageId' => $messageId,
                'filename' => $filename,
                'mimeType' => $part->getMimeType(),
            ];
        }

        $parts = $part->getParts();
        if (! empty($parts)) {
            foreach ($parts as $subPart) {
                $this->extractAttachmentIds($subPart, $messageId, $attachments);
            }
        }
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
                // Ignore and fallback
            }
        }

        if ($internalDate) {
            try {
                return now()->setTimestamp((int) ($internalDate / 1000))->toIso8601String();
            } catch (\Exception $e) {
                // Ignore and fallback
            }
        }

        return now()->toIso8601String();
    }

    private function loadUserPreferences(int $userId): array
    {
        $cacheKey = "user_prefs:{$userId}";

        return Cache::remember($cacheKey, 300, function () {
            return [
                'preferredTone' => 'professional',
                'autoSendEnabled' => false,
                'autoSendConfidenceThreshold' => 0.90,
                'autoSendRiskThreshold' => 0.10,
                'ignoredSenderPatterns' => ['noreply@', 'no-reply@', 'mailer-daemon@', 'postmaster@'],
            ];
        });
    }

    private function clearAccountCache(string $gmailEmail): void
    {
        Cache::forget('ingestion_account:'.$gmailEmail);
    }
}
