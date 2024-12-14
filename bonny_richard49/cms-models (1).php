<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Traits\Auditable;

class Content extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'published_at',
        'author_id',
        'category_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'status' => 'boolean',
    ];

    protected $dates = [
        'published_at',
    ];

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
        return $this->morphMany(Media::class, 'mediable');
    }

    public function scopePublished($query)
    {
        return $query->where('status', true)
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', false)
                    ->orWhereNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->status && 
               $this->published_at && 
               $this->published_at->lte(now());
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->author_id)) {
                $model->author_id = auth()->id();
            }
        });
    }
}

class Category extends Model 
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function contents()
    {
        return $this->hasMany(Content::class);
    }
}

class Tag extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function contents()
    {
        return $this->belongsToMany(Content::class);
    }
}

class Media extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'file_name',
        'mime_type',
        'path',
        'size',
        'mediable_type',
        'mediable_id',
    ];

    public function mediable()
    {
        return $this->morphTo();
    }
}