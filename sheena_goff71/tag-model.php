<?php

namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphToMany};
use Illuminate\Support\Str;

class Tag extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'metadata',
        'parent_id',
        'order',
        'is_protected'
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_protected' => 'boolean',
        'order' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            $tag->slug = $tag->slug ?? Str::slug($tag->name);
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name') && !$tag->isDirty('slug')) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Tag::class, 'parent_id')->orderBy('order');
    }

    public function content(): MorphToMany
    {
        return $this->morphedByMany(Content::class, 'taggable');
    }

    public function isProtected(): bool
    {
        return $this->is_protected;
    }

    public function hasActiveRelationships(): bool
    {
        return $this->content()->count() > 0 || $this->children()->count() > 0;
    }

    public function getFullHierarchyAttribute(): array
    {
        $hierarchy = [];
        $current = $this;

        while ($current->parent) {
            array_unshift($hierarchy, $current->parent);
            $current = $current->parent;
        }

        return $hierarchy;
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
    }
}
