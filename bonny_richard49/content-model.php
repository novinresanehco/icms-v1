<?php

namespace App\Core\Models;

use App\Core\Security\SecurityManager;
use App\Core\Services\ValidationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'type',
        'status',
        'metadata',
        'author_id',
        'category_id',
        'published_at',
        'order',
        'parent_id',
        'template_id',
        'visibility',
    ];

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = [
        'published_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $hidden = [
        'secure_data',
        'deleted_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            if (!$content->slug) {
                $content->slug = static::generateUniqueSlug($content->title);
            }
            $content->validateBeforeSave();
        });

        static::updating(function ($content) {
            if ($content->isDirty('title') && !$content->isDirty('slug')) {
                $content->slug = static::generateUniqueSlug($content->title);
            }
            $content->validateBeforeSave();
        });

        static::deleting(function ($content) {
            DB::transaction(function () use ($content) {
                $content->categories()->detach();
                $content->tags()->detach();
                $content->media()->delete();
                $content->versions()->delete();
            });
        });
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function media()
    {
        return $this->hasMany(Media::class);
    }

    public function versions()
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function parent()
    {
        return $this->belongsTo(Content::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Content::class, 'parent_id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where('published_at', '<=', now());
    }

    public function scopeVisible($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeWithTag($query, $tagId)
    {
        return $query->whereHas('tags', function ($query) use ($tagId) {
            $query->where('id', $tagId);
        });
    }

    public function publish()
    {
        $this->status = 'published';
        $this->published_at = now();
        $this->save();

        event(new ContentPublished($this));
    }

    public function unpublish()
    {
        $this->status = 'draft';
        $this->published_at = null;
        $this->save();

        event(new ContentUnpublished($this));
    }

    public function createVersion()
    {
        return ContentVersion::create([
            'content_id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'version' => $this->versions()->count() + 1,
            'created_by' => auth()->id(),
        ]);
    }

    public function restoreVersion(ContentVersion $version)
    {
        $this->update([
            'title' => $version->title,
            'content' => $version->content,
            'metadata' => $version->metadata,
        ]);

        event(new ContentVersionRestored($this, $version));
    }

    public function validateBeforeSave()
    {
        $validator = app(ValidationService::class);
        
        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:contents,slug,' . $this->id,
            'content' => 'required|string',
            'type' => 'required|string|max:50',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'published_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];

        $validator->validateData($this->attributesToArray(), $rules);
    }

    protected static function generateUniqueSlug($title)
    {
        $slug = str_slug($title);
        $count = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = str_slug($title) . '-' . $count++;
        }

        return $slug;
    }
}
