<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Content extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'slug',
        'content',
        'excerpt',
        'meta_title',
        'meta_description',
        'featured_image',
        'status',
        'published_at',
        'author_id',
        'template',
        'order',
        'parent_id',
        'settings'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'settings' => 'array',
        'order' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            if (empty($content->slug)) {
                $content->slug = Str::slug($content->title);
            }
        });

        static::updating(function ($content) {
            if ($content->isDirty('title') && !$content->isDirty('slug')) {
                $content->slug = Str::slug($content->title);
            }
        });
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent()
    {
        return $this->belongsTo(Content::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Content::class, 'parent_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where('published_at', '<=', now());
    }

    public function scopeDrafts($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getUrlAttribute(): string
    {
        return url($this->type . '/' . $this->slug);
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->status === 'published' 
            && $this->published_at 
            && $this->published_at->lte(now());
    }
}
