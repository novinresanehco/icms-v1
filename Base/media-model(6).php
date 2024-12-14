<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'filename',
        'path',
        'disk',
        'mime_type',
        'size',
        'user_id',
        'title',
        'alt_text',
        'description',
        'category',
        'metadata',
        'thumbnails'
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array',
        'thumbnails' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrl(string $size = 'medium'): ?string
    {
        if (empty($this->thumbnails[$size])) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->thumbnails[$size]);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function scopeImages($query)
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    public function scopeDocuments($query)
    {
        return $query->where('mime_type', 'not like', 'image/%');
    }
}
