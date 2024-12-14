<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    public static array $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:tags,slug',
        'description' => 'nullable|string',
        'type' => 'nullable|string|max:50'
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class);
    }

    public function scopeGeneral($query)
    {
        return $query->where('type', 'general');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getUrlAttribute(): string
    {
        return route('tags.show', $this->slug);
    }
}