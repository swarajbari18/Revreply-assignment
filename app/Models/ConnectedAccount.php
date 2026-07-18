<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectedAccountStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'user_id',
    'gmail_email',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'status',
    'watch_expiration',
    'last_history_id',
])]
#[Hidden([
    'access_token',
    'refresh_token',
])]
class ConnectedAccount extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (ConnectedAccount $account) {
            Cache::forget('ingestion_account:'.$account->gmail_email);
        });

        static::deleted(function (ConnectedAccount $account) {
            Cache::forget('ingestion_account:'.$account->gmail_email);
        });
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'watch_expiration' => 'datetime',
            'status' => ConnectedAccountStatus::class,
        ];
    }

    /**
     * This connected Gmail mailbox belongs to one RevReply user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConnected(): bool
    {
        return $this->status === ConnectedAccountStatus::Connected;
    }

    /**
     * Permanent OAuth failure path: mark disconnected and clear tokens.
     */
    public function markDisconnected(): void
    {
        $this->forceFill([
            'status' => ConnectedAccountStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ])->save();
    }
}
