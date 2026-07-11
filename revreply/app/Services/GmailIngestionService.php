<?php

declare(strict_types=1);

namespace App\Services;

class GmailIngestionService
{
    public function ingest(array $data): void
    {
        logger()->info('Webhook received', $data);
    }
}
