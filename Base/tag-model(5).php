<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\{Model, Relations\BelongsToMany, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tag extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'is_featured',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_tags')
            ->withTimestamps();
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
