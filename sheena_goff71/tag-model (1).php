<?php

namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Core\Content\Models\Content;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($tag) {
            $tag->slug = $tag->generateUniqueSlug();
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name')) {
                $tag->slug = $tag->generateUniqueSlug();
            }
        });
    }

    /**
     * Get the contents that belong to the tag.
     */
    public function contents(): MorphToMany
    {
        return $this->morphedByMany(Content::class, 'taggable')
                    ->withTimestamps()
                    ->withPivot('order');
    }

    /**
     * Generate a unique slug for the tag.
     */
    protected function generateUniqueSlug(): string
    {
        $slug = Str::slug($this->name);
        $count = static::whereRaw("slug RLIKE '^{$slug}(-[0-9]+)?$'")->count();

        return $count ? "{$slug}-{$count}" : $slug;
    }

    /**
     * Scope a query to filter tags by name.
     */
    public function scopeFilterByName($query, string $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%");
    }

    /**
     * Scope a query to order tags by content count.
     */
    public function scopeOrderByContentCount($query, string $direction = 'desc')
    {
        return $query->withCount('contents')
                    ->orderBy('contents_count', $direction);
    }
}
