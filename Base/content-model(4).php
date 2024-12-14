<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'type',
        'content',
        'excerpt',
        'template_id',
        'author_id',
        'status',
        'published_at',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array',
        'published_at' => 'datetime'
    ];

    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($content) {
            if (empty($content->slug)) {
                $content->slug = Str::slug($content->title);
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function meta(): HasMany
    {
        return $this->hasMany(ContentMeta::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'content_categories');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'content_tags');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && 
            $this->published_at && 
            $this->published_at->isPast();
    }

    public function getMetaValue(string $key, $default = null)
    {
        return $this->meta->where('key', $key)->first()?->value ?? $default;
    }
}
