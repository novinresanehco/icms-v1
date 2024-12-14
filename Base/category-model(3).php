<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'order',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'is_visible'
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'order' => 'integer'
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_categories')
            ->withTimestamps();
    }

    public function getAllChildren(): Collection
    {
        $children = collect();
        
        foreach ($this->children as $child) {
            $children->push($child);
            $children = $children->merge($child->getAllChildren());
        }
        
        return $children;
    }

    public function getBreadcrumb(): Collection
    {
        $breadcrumb = collect();
        $category = $this;

        while ($category) {
            $breadcrumb->prepend($category);
            $category = $category->parent;
        }

        return $breadcrumb;
    }
}
