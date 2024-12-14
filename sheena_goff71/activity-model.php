<?php

namespace App\Core\Activity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class Activity extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'subject',
        'data',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDescriptionAttribute(): string
    {
        return $this->data['description'] ?? '';
    }

    public function getFormattedDataAttribute(): array
    {
        return array_merge([
            'timestamp' => $this->created_at->toISOString(),
            'user' => $this->user ? $this->user->name : 'System',
            'type' => $this->type,
            'ip_address' => $this->ip_address,
        ], $this->data);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeBySubject($query, string $subject)
    {
        return $query->where('subject', $subject);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function isSystem(): bool
    {
        return $this->user_id === null;
    }
}
