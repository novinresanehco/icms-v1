<?php

namespace App\Core\Webhook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    protected $fillable = [
        'event',
        'url',
        'secret',
        'is_active',
        'retry_limit',
        'timeout',
        'last_triggered_at',
        'failed_attempts'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'retry_limit' => 'integer',
        'timeout' => 'integer',
        'failed_attempts' => 'integer',
        'last_triggered_at' => 'datetime'
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function canRetry(): bool
    {
        return $this->failed_attempts < $this->retry_limit;
    }

    public function incrementFailedAttempts(): void
    {
        $this->increment('failed_attempts');
    }

    public function resetFailedAttempts(): void
    {
        $this->update(['failed_attempts' => 0]);
    }

    public function generateSignature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->secret);
    }
}
