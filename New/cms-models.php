<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany};

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'author_id',
        'published_at',
        'checksum'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $dates = [
        'published_at',
        'deleted_at'
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class)
            ->withTimestamps()
            ->withPivot(['order', 'caption']);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'filename',
        'path',
        'type',
        'size',
        'mime_type',
        'metadata',
        'uploader_id',
        'checksum'
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $dates = [
        'deleted_at'
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class)
            ->withTimestamps()
            ->withPivot(['order', 'caption']);
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }
}