<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'path',
        'mime_type',
        'size',
        'alt_text',
        'title',
        'description',
        'mediable_type',
        'mediable_id',
        'order',
        'meta_data'
    ];

    protected $casts = [
        'size' => 'integer',
        'order' => 'integer',
        'meta_data' => 'array'
    ];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getUrlAttribute(): string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : '';
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->path || !in_array($this->type, ['image', 'video'])) {
            return null;
        }

        // Logic for generating thumbnail URL based on media type
        return $this->url;
    }

    public function getHumanReadableSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    public function isDocument(): bool
    {
        return $this->type === 'document';
    }
}
