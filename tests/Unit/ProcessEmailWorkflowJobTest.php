<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\Workflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessEmailWorkflowJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_instantiated_with_ids(): void
    {
        $job = new ProcessEmailWorkflowJob(1, 2);

        $this->assertSame(1, $job->workflowId);
        $this->assertSame(2, $job->connectedAccountId);
    }

    public function test_job_implements_should_queue(): void
    {
        $job = new ProcessEmailWorkflowJob(1, 2);

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function test_job_has_tries_and_backoff(): void
    {
        $job = new ProcessEmailWorkflowJob(1, 2);

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 10, 20], $job->backoff());
    }

    public function test_job_logs_picked_up_audit_event(): void
    {
        $workflow = Workflow::factory()->create();

        $job = new ProcessEmailWorkflowJob($workflow->id, $workflow->connected_account_id);
        $job->handle();

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_picked_up',
        ]);
    }
}
