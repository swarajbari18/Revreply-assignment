<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GmailIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GmailWebhookController extends Controller
{
    public function __construct(
        protected GmailIngestionService $gmailIngestionService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $this->gmailIngestionService->ingest($payload);

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
