<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final class EmailContext
{
    /**
     * @param  array<int, array{role: string, body: string, timestamp: string, from: string, to: string, subject: string}>  $messages
     * @param  list<string>  $attachmentTexts
     * @param  list<string>  $ignoredSenderPatterns
     */
    public function __construct(
        public readonly string $threadId,
        public readonly string $latestMessageId,
        public readonly string $gmailEmail,
        public readonly array $messages,
        public readonly array $attachmentTexts,
        public readonly string $preferredTone,
        public readonly bool $autoSendEnabled,
        public readonly float $autoSendConfidenceThreshold,
        public readonly float $autoSendRiskThreshold,
        public readonly array $ignoredSenderPatterns,
        public readonly string $correlationId,
        public readonly ?string $pendingDraftBody = null
    ) {}

    public function toArray(): array
    {
        return [
            'threadId' => $this->threadId,
            'latestMessageId' => $this->latestMessageId,
            'gmailEmail' => $this->gmailEmail,
            'messages' => $this->messages,
            'attachmentTexts' => $this->attachmentTexts,
            'preferredTone' => $this->preferredTone,
            'autoSendEnabled' => $this->autoSendEnabled,
            'autoSendConfidenceThreshold' => $this->autoSendConfidenceThreshold,
            'autoSendRiskThreshold' => $this->autoSendRiskThreshold,
            'ignoredSenderPatterns' => $this->ignoredSenderPatterns,
            'correlationId' => $this->correlationId,
            'pendingDraftBody' => $this->pendingDraftBody,
        ];
    }
}
