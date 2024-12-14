<?php

namespace App\Core\Queue\Models;

use Illuminate\Database\Eloquent\Model;

class QueueJob extends Model
{
    protected $fillable = [
        'type',
        'data',
        'status',
        'priority',
        'queue',
        'delay',
        'attempts',
        'max_attempts',
        'progress',
        'result',
        'error',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'data' => 'array',
        'result' => 'array',
        'delay' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'progress' => 'integer'
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

    public function canRetry(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['pending', 'failed']);
    }

    public function shouldProcess(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        if ($this->delay && $this->delay->isFuture()) {
            return false;
        }

        return true;
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => $progress]);
    }

    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now()
        ]);
    }

    public function markAsCompleted(array $result = []): void
    {
        $this->update([
            'status' => 'completed',
            'result' => $result,
            'completed_at' => now()
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now()
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByQueue($query, string $queue)
    {
        return $query->where('queue', $queue);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }
}
