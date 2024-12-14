<?php

namespace App\Core\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\{
    BelongsTo,
    HasMany,
    BelongsToMany,
    MorphMany
};
use App\Core\User\Models\User;
use App\Core\Tag\Models\Tag;
use App\Core\Category\Models\Category;
use App\Core\Media\Models\Media;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'excerpt',
        'status',
        'author_id',
        'content_type',
        'template',
        'published_at',
        'metadata',
        'seo_data',
        'sort_order'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array',
        'seo_data' => 'array',
        'sort_order' => 'integer'
    ];

    protected $dates = [
        'published_at'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            if (empty($content->author_id)) {
                $content->author_id = auth()->id();
            }

            if (!isset($content->status)) {
                $content->status = 'draft';
            }
        });

        static::updating(function ($content) {
            if ($content->isDirty('status') && $content->status === 'published') {
                $content->published_at = $content->published_at ?? now();
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Content::class, 'parent_id')->orderBy('revision_number', 'desc');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'parent_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
                    ->withTimestamps()
                    ->withPivot('order')
                    ->orderBy('pivot_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
                    ->withTimestamps()
                    ->withPivot('order')
                    ->orderBy('pivot_order');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class)
                    ->withTimestamps()
                    ->withPivot(['type', 'order'])
                    ->orderBy('pivot_order');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                    ->where('published_at', '<=', now());
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('content_type', $type);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('content', 'like', "%{$term}%")
              ->orWhere('excerpt', 'like', "%{$term}%");
        });
    }

    public function scopeWithinCategories($query, array $categoryIds)
    {
        return $query->whereHas('categories', function($q) use ($categoryIds) {
            $q->whereIn('categories.id', $categoryIds);
        });
    }

    public function scopeWithinTags($query, array $tagIds)
    {
        return $query->whereHas('tags', function($q) use ($tagIds) {
            $q->whereIn('tags.id', $tagIds);
        });
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && 
               $this->published_at && 
               $this->published_at->lte(now());
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getFeaturedImage(): ?Media
    {
        return $this->media()
                    ->wherePivot('type', 'featured')
                    ->orderBy('pivot_order')
                    ->first();
    }

    public function getGalleryImages(): Collection
    {
        return $this->media()
                    ->wherePivot('type', 'gallery')
                    ->orderBy('pivot_order')
                    ->get();
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
            'status' => $this->status,
            'content_type' => $this->content_type,
            'author' => $this->author?->name,
            'tags' => $this->tags->pluck('name'),
            'categories' => $this->categories->pluck('name'),
            'published_at' => $this->published_at?->toDateTimeString(),
            'created_at' => $this->created_at->toDateTimeString(),
            'updated_at' => $this->updated_at->toDateTimeString()
        ];
    }
}
