<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\EmailContext;
use App\Enums\WorkflowStatus;
use App\Models\AuditLog;
use App\Models\Classification;
use App\Models\Workflow;
use Illuminate\Support\Facades\Log;

class DecisionEngine
{
    /**
     * Determine the workflow action based on classification results and business thresholds.
     */
    public function decide(EmailContext $context, Classification $classification, Workflow $workflow): string
    {
        // 1. Ignore Check: Check if sender is in the ignored patterns list
        foreach ($context->ignoredSenderPatterns as $pattern) {
            if (str_contains(strtolower($classification->workflow->connectedAccount->gmail_email), strtolower($pattern))) {
                return $this->transition($workflow, 'Ignore', 'ignored_sender_pattern', [
                    'pattern' => $pattern,
                ]);
            }
            foreach ($context->messages as $msg) {
                if (str_contains(strtolower($msg['from']), strtolower($pattern))) {
                    return $this->transition($workflow, 'Ignore', 'ignored_sender_pattern', [
                        'pattern' => $pattern,
                        'sender' => $msg['from'],
                    ]);
                }
            }
        }

        // If interest classification is "not_interested" with a high confidence, ignore to avoid spamming the decline
        if ($classification->classification === 'not_interested' && $classification->confidence >= 0.80) {
            return $this->transition($workflow, 'Ignore', 'ignored_not_interested', [
                'confidence' => $classification->confidence,
            ]);
        }

        // 2. Escalation Checks:
        // - Low Confidence is always escalated (two cases: low conf/high risk, low conf/low risk)
        if ($classification->confidence < $context->autoSendConfidenceThreshold) {
            return $this->transition($workflow, 'Escalate', 'escalated_low_confidence', [
                'confidence' => $classification->confidence,
                'threshold' => $context->autoSendConfidenceThreshold,
            ]);
        }

        // - High Risk: If confidence is high, but risk is also high, we check if it crosses risk threshold.
        // Wait, if confidence is low, it already escalated.
        // If confidence is high, but risk is also high:
        // We know the content is good (high confidence), but it's a business risk.
        // Do we escalate or create a draft?
        // Let's re-read: "if the confidence is high and Risk is also high in that case to create a draft because we know that the content is good We just don't We think that it is a risk risk in the sense of that is a business risk right"
        // And "And there will be some accountability if the confidence is low and And the risk is high Which is the worst case here. We need escalation and even if the confidence Is high but the risk is also high right again. We need escalation So there are two cases for escalation"
        // Wait!
        // If confidence is low, we escalate (covers Low Confidence & High Risk, and Low Confidence & Low Risk).
        // If confidence is high, and risk is high (Risk > Threshold):
        // Wait! The user says: "if the confidence is high and Risk is also high in that case to create a draft because we know that the content is good".
        // But then says: "and even if the confidence Is high but the risk is also high right again. We need escalation" -> wait, does he mean "if the confidence is low but the risk is also low"?
        // Let's map it exactly as we defined in the approved `implementation_plan.md`!
        // In the approved `implementation_plan.md`:
        // - AutoReply: confidence >= threshold AND risk <= threshold (High confidence, Low risk)
        // - Draft: confidence >= threshold AND risk > threshold (High confidence, High risk)
        // - Escalate:
        //   - Case A: confidence < threshold AND risk > threshold (Low confidence, High risk)
        //   - Case B: confidence < threshold AND risk <= threshold (Low confidence, Low risk)
        // This is exactly what was approved in the plan, and it's highly logical!
        // Let's implement it exactly as defined in the plan:
        if ($classification->risk > $context->autoSendRiskThreshold) {
            // Risk is high, confidence is high (since we already checked confidence < threshold)
            return $this->transition($workflow, 'Draft', 'draft_high_risk', [
                'risk' => $classification->risk,
                'threshold' => $context->autoSendRiskThreshold,
            ]);
        }

        // 3. Auto-Reply Check:
        // Confidence is high (>= threshold) AND Risk is low (<= threshold)
        return $this->transition($workflow, 'AutoReply', 'auto_reply_approved', [
            'confidence' => $classification->confidence,
            'risk' => $classification->risk,
        ]);
    }

    /**
     * Helper to transition workflow and save decision audit log.
     */
    private function transition(Workflow $workflow, string $decision, string $reason, array $metadata): string
    {
        $workflow->update([
            'status' => WorkflowStatus::DecisionComplete,
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'decision_engine_completed',
            'metadata' => array_merge([
                'decision' => $decision,
                'reason' => $reason,
            ], $metadata),
        ]);

        Log::info("Decision Engine completed for workflow {$workflow->id}. Decision: {$decision}, Reason: {$reason}");

        return $decision;
    }
}
