<?php

namespace App\Core\Import\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'type',
        'data',
        'status',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'errors',
        'completed_at'
    ];

    protected $casts = [
        'data' => 'array',
        'errors' => 'array',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'failed_rows' => 'integer',
        'completed_at' => 'datetime'
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

    public function canCancel(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, ['failed', 'cancelled']);
    }

    public function isComplete(): bool
    {
        return $this->processed_rows + $this->failed_rows >= $this->total_rows;
    }

    public function getProgress(): float
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return round(
            ($this->processed_rows + $this->failed_rows) / $this->total_rows * 100,
            2
        );
    }

    public function incrementProcessedRows(): void
    {
        $this->increment('processed_rows');
    }

    public function incrementFailedRows(): void
    {
        $this->increment('failed_rows');
    }

    public function resetCounters(): void
    {
        $this->update([
            'processed_rows' => 0,
            'failed_rows' => 0,
            'errors' => []
        ]);
    }

    public function addError(array $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = $error;
        
        $this->update(['errors' => $errors]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now()
        ]);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'errors' => array_merge($this->errors ?? [], [
                ['error' => $reason, 'timestamp' => now()]
            ])
        ]);
    }
}
