<?php

namespace App\Core\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class Notification extends Model
{
    protected $fillable = [
        'type',
        'user_id',
        'data',
        'read_at',
        'status',
        'retry_count',
        'last_error'
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'retry_count' => 'integer'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): bool
    {
        return $this->update(['read_at' => now()]);
    }

    public function markAsDelivered(): bool
    {
        return $this->update(['status' => 'delivered']);
    }

    public function shouldRetry(): bool
    {
        return $this->retry_count < config('notifications.max_retries', 3);
    }

    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
