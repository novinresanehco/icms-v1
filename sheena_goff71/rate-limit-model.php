<?php

namespace App\Core\RateLimit\Models;

use Illuminate\Database\Eloquent\Model;

class RateLimit extends Model
{
    protected $fillable = [
        'key',
        'attempts',
        'exceeded_at',
        'ip_address',
        'user_agent',
        'last_attempt_at'
    ];

    protected $casts = [
        'attempts' => 'integer',
        'exceeded_at' => 'datetime',
        'last_attempt_at' => 'datetime'
    ];

    public function isExceeded(): bool
    {
        return $this->exceeded_at !== null;
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
        $this->update(['last_attempt_at' => now()]);
    }

    public function markAsExceeded(): void
    {
        if (!$this->isExceeded()) {
            $this->update(['exceeded_at' => now()]);
        }
    }

    public function reset(): void
    {
        $this->update([
            'attempts' => 0,
            'exceeded_at' => null,
            'last_attempt_at' => null
        ]);
    }

    public function scopeExceeded($query)
    {
        return $query->whereNotNull('exceeded_at');
    }

    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('last_attempt_at', '>=', now()->subMinutes($minutes));
    }
}
