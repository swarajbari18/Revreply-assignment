<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'idempotency_key',
    'connected_account_id',
    'processed_at',
])]
class ProcessedNotification extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
