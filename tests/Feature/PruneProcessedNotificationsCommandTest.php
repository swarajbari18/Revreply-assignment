<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcessedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneProcessedNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_entries_older_than_14_days(): void
    {
        $old = ProcessedNotification::factory()->create([
            'processed_at' => now()->subDays(15),
        ]);

        $recent = ProcessedNotification::factory()->create([
            'processed_at' => now()->subDays(13),
        ]);

        $current = ProcessedNotification::factory()->create([
            'processed_at' => now(),
        ]);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseMissing('processed_notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('processed_notifications', ['id' => $recent->id]);
        $this->assertDatabaseHas('processed_notifications', ['id' => $current->id]);
    }

    public function test_does_nothing_when_no_old_entries_exist(): void
    {
        $recent = ProcessedNotification::factory()->create([
            'processed_at' => now()->subDays(5),
        ]);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseHas('processed_notifications', ['id' => $recent->id]);
    }
}
