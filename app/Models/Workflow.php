<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkflowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'connected_account_id',
    'thread_id',
    'latest_message_id',
    'draft_id',
    'status',
    'failure_reason',
    'correlation_id',
    'started_at',
    'completed_at',
])]
class Workflow extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function classification(): HasOne
    {
        return $this->hasOne(Classification::class);
    }
}
