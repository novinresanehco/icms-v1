<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $fillable = [
        'filename',
        'original_filename',
        'mime_type',
        'size',
        'path',
        'disk',
        'meta'
    ];

    protected $casts = [
        'size' => 'integer',
        'meta' => 'array'
    ];

    public function thumbnails(): HasMany
    {
        return $this->hasMany(MediaThumbnail::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrl(string $size): ?string
    {
        $thumbnail = $this->thumbnails()->where('size', $size)->first();
        return $thumbnail ? Storage::disk($this->disk)->url($thumbnail->path) : null;
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($media) {
            // Delete the actual file
            Storage::disk($media->disk)->delete($media->path);

            // Delete all thumbnails
            foreach ($media->thumbnails as $thumbnail) {
                Storage::disk($media->disk)->delete($thumbnail->path);
            }
        });
    }
}
