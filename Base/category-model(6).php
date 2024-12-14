<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsTo, MorphMany};

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'type',
        'status',
        'order',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array'
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function meta(): MorphMany
    {
        return $this->morphMany(Meta::class, 'metable');
    }

    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getPathAttribute(): string
    {
        $path = collect([$this]);
        $ancestor = $this->parent;

        while ($ancestor) {
            $path->prepend($ancestor);
            $ancestor = $ancestor->parent;
        }

        return $path->pluck('name')->implode(' > ');
    }
}
