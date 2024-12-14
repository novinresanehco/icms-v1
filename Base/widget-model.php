<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Widget extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'key',
        'description',
        'type',
        'content',
        'settings',
        'position',
        'order',
        'is_active',
        'is_system',
        'cache_ttl',
        'author_id'
    ];

    protected $casts = [
        'content' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'cache_ttl' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    public static array $rules = [
        'name' => 'required|string|max:255',
        'key' => 'required|string|max:255|unique:widgets,key',
        'description' => 'nullable|string',
        'type' => 'required|string|max:50',
        'content' => 'nullable|array',
        'settings' => 'nullable|array',
        'position' => 'nullable|string|max:50',
        'order' => 'integer',
        'is_active' => 'boolean',
        'cache_ttl' => 'integer|min:0'
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePosition($query, string $position)
    {
        return $query->where('position', $position);
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function getContent(string $key, mixed $default = null): mixed
    {
        return data_get($this->content, $key, $default);
    }

    public function getCacheKey(): string
    {
        return "widget:{$this->key}:" . md5(json_encode($this->content));
    }
}