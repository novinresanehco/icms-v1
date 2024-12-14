<?php

namespace App\Core\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = [
        'name',
        'properties',
        'user_id',
        'session_id',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'properties' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProperty(string $key, $default = null)
    {
        return $this->properties[$key] ?? $default;
    }

    public function hasProperty(string $key): bool
    {
        return array_key_exists($key, $this->properties);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('name', $eventType);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeBySession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeInTimeRange($query, $start, $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}
