<?php

namespace App\Core\Link\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Link extends Model
{
    protected $fillable = [
        'original_url',
        'short_code',
        'expires_at',
        'max_clicks',
        'is_active'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'max_clicks' => 'integer',
        'is_active' => 'boolean'
    ];

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function hasReachedMaxClicks(): bool
    {
        return $this->max_clicks && $this->clicks()->count() >= $this->max_clicks;
    }

    public function getClickCount(): int
    {
        return $this->clicks()->count();
    }

    public function getShortUrl(): string
    {
        return url("/l/{$this->short_code}");
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
                    ->where('expires_at', '<', now());
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('short_code', $code);
    }
}
