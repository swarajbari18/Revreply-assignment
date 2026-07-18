<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\EmailContext;
use App\Enums\WorkflowStatus;
use App\Exceptions\GeminiRateLimitException;
use App\Models\AuditLog;
use App\Models\Classification;
use App\Models\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIUnderstandingService
{
    /**
     * Analyze email context and generate structured classification and draft response.
     *
     * @throws GeminiRateLimitException
     * @throws \Exception
     */
    public function understand(EmailContext $context, Workflow $workflow): Classification
    {
        $apiKey = config('gemini.api_key');
        if (empty($apiKey)) {
            Log::error('Gemini API key is not configured.');
            $workflow->update([
                'status' => WorkflowStatus::Failed,
                'failure_reason' => 'llm_config_error',
            ]);
            AuditLog::create([
                'workflow_id' => $workflow->id,
                'correlation_id' => $workflow->correlation_id,
                'event' => 'workflow_failed_config',
                'metadata' => ['error' => 'Missing Gemini API key'],
            ]);
            throw new \Exception('Missing Gemini API key');
        }

        $model = config('gemini.model');
        $temperature = config('gemini.temperature');
        $topP = config('gemini.top_p');
        $topK = config('gemini.top_k');

        $systemInstruction = "You are a professional sales auto-responder. Analyze the provided email thread history, tone preferences, and attachments. Classify the customer interest into one of these exact categories: 'interested', 'not_interested', 'meeting_request', 'unclear'. Evaluate the confidence of your classification (0.0 to 1.0) and the business risk of sending an automatic reply (0.0 to 1.0). Finally, generate a draft response in the requested tone: '{$context->preferredTone}'. The draft response must NOT contain placeholders, signature blocks, or templates. Write the exact response content.";

        if (!empty($context->pendingDraftBody)) {
            $systemInstruction .= "\n\nNote: A draft response was previously created for this thread:\n\"{$context->pendingDraftBody}\"\nThe customer has replied since then. Analyze their reply in context of this previous draft and adjust/update the response accordingly.";
        }

        $historyText = '';
        foreach ($context->messages as $msg) {
            $historyText .= sprintf(
                "From: %s\nTo: %s\nDate: %s\nSubject: %s\nBody:\n%s\n--------------------\n",
                $msg['from'],
                $msg['to'],
                $msg['timestamp'],
                $msg['subject'],
                $msg['body']
            );
        }

        $attachmentsText = '';
        if (!empty($context->attachmentTexts)) {
            $attachmentsText = "\nParsed Email Attachments Plain-Text Content:\n";
            foreach ($context->attachmentTexts as $idx => $attText) {
                $attachmentsText .= 'Attachment ' . ($idx + 1) . ":\n{$attText}\n--------------------\n";
            }
        }

        $userPrompt = "Analyze the following email thread history and generate the classification and response:\n\nEmail Thread History:\n{$historyText}{$attachmentsText}";

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'topP' => $topP,
                'topK' => $topK,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'reasoning' => [
                            'type' => 'STRING',
                            'description' => 'Explanation for the chosen classification, confidence, risk, and drafted response.',
                        ],
                        'classification' => [
                            'type' => 'STRING',
                            'enum' => ['interested', 'not_interested', 'meeting_request', 'unclear'],
                        ],
                        'confidence' => [
                            'type' => 'NUMBER',
                        ],
                        'risk' => [
                            'type' => 'NUMBER',
                        ],
                        'suggested_draft' => [
                            'type' => 'STRING',
                        ],
                    ],
                    'required' => ['reasoning', 'classification', 'confidence', 'risk', 'suggested_draft'],
                ],
            ],
        ];

        $classificationData = $this->callGeminiWithRetry($endpoint, $payload, $userPrompt, $systemInstruction);

        if ($classificationData === null) {
            Log::warning("Gemini parsing failed twice. Using fallback classification for workflow {$workflow->id}.");
            $classification = Classification::create([
                'workflow_id' => $workflow->id,
                'classification' => 'unclear',
                'confidence' => 0.0,
                'risk' => 1.0,
                'generated_draft' => '',
                'prompt_version' => '1.0',
                'model_version' => $model,
            ]);
        } else {
            $classification = Classification::create([
                'workflow_id' => $workflow->id,
                'classification' => $classificationData['classification'],
                'confidence' => (float) $classificationData['confidence'],
                'risk' => (float) $classificationData['risk'],
                'generated_draft' => $classificationData['suggested_draft'],
                'prompt_version' => '1.0',
                'model_version' => $model,
            ]);
        }

        $workflow->update([
            'status' => WorkflowStatus::AiComplete,
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'ai_classification_completed',
            'metadata' => [
                'reasoning' => $classificationData['reasoning'] ?? null,
                'classification' => $classification->classification,
                'confidence' => $classification->confidence,
                'risk' => $classification->risk,
            ],
        ]);

        return $classification;
    }

    /**
     * Send POST request to Gemini, retrying once on JSON/schema failures.
     */
    private function callGeminiWithRetry(string $endpoint, array $payload, string $userPrompt, string $systemInstruction, bool $isRetry = false): ?array
    {
        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, $payload);

            if ($response->status() === 429) {
                Log::warning('Gemini API rate limit hit (429).');
                throw new GeminiRateLimitException('Gemini API rate limit hit.');
            }

            if ($response->failed()) {
                Log::error('Gemini API request failed. Status: ' . $response->status() . ' Body: ' . $response->body());
                throw new \Exception('Gemini API request failed with status: ' . $response->status());
            }

            $body = $response->json();
            $candidates = $body['candidates'] ?? [];
            if (empty($candidates)) {
                throw new \Exception('Gemini returned empty candidates.');
            }

            $responseText = $candidates[0]['content']['parts'][0]['text'] ?? '';
            $data = json_decode($responseText, true);

            if ($data === null || !isset($data['reasoning'], $data['classification'], $data['confidence'], $data['risk'], $data['suggested_draft'])) {
                throw new \Exception('Gemini output failed schema validation: ' . $responseText);
            }

            return $data;

        } catch (GeminiRateLimitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Gemini request exception: ' . $e->getMessage());

            if (!$isRetry) {
                Log::info('Retrying Gemini query with error context...');
                $errorPayload = $payload;
                $errorPayload['contents'][] = [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Your previous response was invalid. Error: ' . $e->getMessage() . '. Please generate a valid JSON string matching the schema exactly.'],
                    ],
                ];

                return $this->callGeminiWithRetry($endpoint, $errorPayload, $userPrompt, $systemInstruction, true);
            }

            return null;
        }
    }
}
