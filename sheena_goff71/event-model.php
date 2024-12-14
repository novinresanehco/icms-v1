<?php

namespace App\Core\Event\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'name',
        'type',
        'data',
        'status',
        'scheduled_at',
        'attempts',
        'result',
        'error',
        'processed_at'
    ];

    protected $casts = [
        'data' => 'array',
        'result' => 'array',
        'scheduled_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer'
    ];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canProcess(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        if ($this->scheduled_at && $this->scheduled_at->isFuture()) {
            return false;
        }

        return true;
    }

    public function canRetry(): bool
    {
        return $this->isFailed() && $this->attempts < config('events.max_attempts', 3);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['pending', 'failed']);
    }

    public function shouldRetry(): bool
    {
        return $this->attempts < config('events.max_attempts', 3);
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    public function updateStatus(string $status): bool
    {
        return $this->update(['status' => $status]);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(array $result = []): void
    {
        $this->update([
            'status' => 'completed',
            'result' => $result,
            'processed_at' => now()
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error
        ]);
    }
}
