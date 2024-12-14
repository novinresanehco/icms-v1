<?php

namespace App\Core\Logger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class Log extends Model
{
    protected $fillable = [
        'type',
        'message',
        'context',
        'level',
        'user_id',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'context' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isError(): bool
    {
        return $this->level === 'error';
    }

    public function isWarning(): bool
    {
        return $this->level === 'warning';
    }

    public function isInfo(): bool
    {
        return $this->level === 'info';
    }

    public function getFormattedContext(): string
    {
        return json_encode($this->context, JSON_PRETTY_PRINT);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOfLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'formatted_context' => $this->getFormattedContext(),
            'user_name' => $this->user ? $this->user->name : null
        ]);
    }
}
