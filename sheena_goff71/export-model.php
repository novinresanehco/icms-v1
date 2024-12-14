<?php

namespace App\Core\Export\Models;

use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    protected $fillable = [
        'type',
        'data',
        'status',
        'format',
        'file_path',
        'total_records',
        'processed_records',
        'errors',
        'completed_at'
    ];

    protected $casts = [
        'data' => 'array',
        'errors' => 'array',
        'total_records' => 'integer',
        'processed_records' => 'integer',
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

    public function getProgress(): float
    {
        if ($this->total_records === 0) {
            return 0;
        }

        return round(
            ($this->processed_records / $this->total_records) * 100,
            2
        );
    }

    public function incrementProcessedRecords(int $count = 1): void
    {
        $this->increment('processed_records', $count);
    }

    public function resetCounters(): void
    {
        $this->update([
            'processed_records' => 0,
            'errors' => [],
            'file_path' => null
        ]);
    }

    public function addError(array $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = $error;
        
        $this->update(['errors' => $errors]);
    }

    public function markAsCompleted(string $filePath): void
    {
        $this->update([
            'status' => 'completed',
            'file_path' => $filePath,
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
