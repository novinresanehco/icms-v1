<?php

namespace App\Core\Health\Models;

use Illuminate\Database\Eloquent\Model;

class HealthCheck extends Model
{
    protected $fillable = [
        'status',
        'checks',
        'notified_at'
    ];

    protected $casts = [
        'checks' => 'array',
        'notified_at' => 'datetime'
    ];

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function hasErrors(): bool
    {
        return !$this->isHealthy();
    }

    public function getFailedChecks(): array
    {
        return collect($this->checks)
            ->filter(fn($check) => $check['status'] === 'error')
            ->toArray();
    }

    public function getWarningChecks(): array
    {
        return collect($this->checks)
            ->filter(fn($check) => $check['status'] === 'warning')
            ->toArray();
    }

    public function markAsNotified(): void
    {
        $this->update(['notified_at' => now()]);
    }
}
