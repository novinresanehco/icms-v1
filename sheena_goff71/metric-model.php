<?php

namespace App\Core\Metric\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    protected $fillable = [
        'name',
        'value',
        'tags',
        'recorded_at'
    ];

    protected $casts = [
        'value' => 'float',
        'tags' => 'array',
        'recorded_at' => 'datetime'
    ];

    public function scopeByName($query, string $name)
    {
        return $query->where('name', $name);
    }

    public function scopeWithTags($query, array $tags)
    {
        foreach ($tags as $key => $value) {
            $query->whereJsonContains("tags->{$key}", $value);
        }
        return $query;
    }

    public function scopeInTimeRange($query, $start, $end)
    {
        return $query->whereBetween('recorded_at', [$start, $end]);
    }

    public function scopeLastDays($query, int $days)
    {
        return $query->where('recorded_at', '>=', now()->subDays($days));
    }
}
