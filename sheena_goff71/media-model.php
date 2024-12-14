<?php

namespace App\Core\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphToMany};

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'file_name',
        'mime_type',
        'size',
        'disk',
        'path',
        'metadata'
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array'
    ];

    protected $appends = [
        'url',
        'thumbnail_url'
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function contents(): MorphToMany
    {
        return $this->morphedByMany(Content::class, 'mediable')
                    ->withTimestamps()
                    ->withPivot(['type', 'order']);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        $thumbnail = $this->variants()->where('type', 'thumbnail')->first();
        return $thumbnail ? Storage::disk($this->disk)->url($thumbnail->path) : null;
    }

    public function getVariantUrl(string $type): ?string
    {
        $variant = $this->variants()->where('type', $type)->first();
        return $variant ? Storage::disk($this->disk)->url($variant->path) : null;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function isDocument(): bool
    {
        return !$this->isImage() && !$this->isVideo();
    }

    public function getDimensionsAttribute(): ?array
    {
        if ($this->isImage() && isset($this->metadata['width'], $this->metadata['height'])) {
            return [
                'width' => $this->metadata['width'],
                'height' => $this->metadata['height']
            ];
        }
        return null;
    }

    public function getDurationAttribute(): ?float
    {
        return $this->isVideo() ? ($this->metadata['duration'] ?? null) : null;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnail_url,
            'type' => $this->isImage() ? 'image' : ($this->isVideo() ? 'video' : 'document'),
            'dimensions' => $this->dimensions,
            'duration' => $this->duration
        ]);
    }
}
