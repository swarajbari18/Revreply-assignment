<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataTransferObjects\EmailContext;
use App\Enums\WorkflowStatus;
use App\Models\Classification;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use App\Services\DecisionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    private DecisionEngine $engine;

    private ConnectedAccount $account;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DecisionEngine;

        $this->account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
        ]);

        $this->workflow = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::AiComplete,
            'correlation_id' => 'corr-123',
        ]);
    }

    private function buildContext(array $messages = [], array $ignoredPatterns = ['noreply@']): EmailContext
    {
        if (empty($messages)) {
            $messages = [
                [
                    'role' => 'user',
                    'body' => 'Hello',
                    'timestamp' => now()->toIso8601String(),
                    'from' => 'sender@example.com',
                    'to' => 'test@gmail.com',
                    'subject' => 'Hi',
                ],
            ];
        }

        return new EmailContext(
            threadId: 'thread123',
            latestMessageId: 'msg123',
            gmailEmail: 'test@gmail.com',
            messages: $messages,
            attachmentTexts: [],
            preferredTone: 'professional',
            autoSendEnabled: true,
            autoSendConfidenceThreshold: 0.90,
            autoSendRiskThreshold: 0.10,
            ignoredSenderPatterns: $ignoredPatterns,
            correlationId: 'corr-123'
        );
    }

    private function createClassification(string $class, float $conf, float $risk): Classification
    {
        return Classification::create([
            'workflow_id' => $this->workflow->id,
            'classification' => $class,
            'confidence' => $conf,
            'risk' => $risk,
            'generated_draft' => 'Draft response',
            'prompt_version' => '1.0',
            'model_version' => 'gemini-2.5-flash',
        ]);
    }

    public function test_decision_engine_auto_reply_happy_path(): void
    {
        $context = $this->buildContext();
        $classification = $this->createClassification('interested', 0.95, 0.05);

        $decision = $this->engine->decide($context, $classification, $this->workflow);

        $this->assertSame('AutoReply', $decision);
        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::DecisionComplete, $this->workflow->status);
    }

    public function test_decision_engine_draft_high_risk(): void
    {
        $context = $this->buildContext();
        // High confidence (0.95 >= 0.90) and high risk (0.15 > 0.10)
        $classification = $this->createClassification('interested', 0.95, 0.15);

        $decision = $this->engine->decide($context, $classification, $this->workflow);

        $this->assertSame('Draft', $decision);
    }

    public function test_decision_engine_escalate_low_confidence(): void
    {
        $context = $this->buildContext();
        // Low confidence (0.85 < 0.90)
        $classification = $this->createClassification('interested', 0.85, 0.05);

        $decision = $this->engine->decide($context, $classification, $this->workflow);

        $this->assertSame('Escalate', $decision);
    }

    public function test_decision_engine_ignore_ignored_sender(): void
    {
        $messages = [
            [
                'role' => 'user',
                'body' => 'Auto notification',
                'timestamp' => now()->toIso8601String(),
                'from' => 'noreply@somecompany.com',
                'to' => 'test@gmail.com',
                'subject' => 'Alert',
            ],
        ];
        $context = $this->buildContext($messages);
        $classification = $this->createClassification('unclear', 0.99, 0.01);

        $decision = $this->engine->decide($context, $classification, $this->workflow);

        $this->assertSame('Ignore', $decision);
    }

    public function test_decision_engine_ignore_not_interested(): void
    {
        $context = $this->buildContext();
        // Interest is 'not_interested' with high confidence (>= 0.80)
        $classification = $this->createClassification('not_interested', 0.85, 0.05);

        $decision = $this->engine->decide($context, $classification, $this->workflow);

        $this->assertSame('Ignore', $decision);
    }
}
