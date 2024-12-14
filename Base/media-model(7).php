<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'path',
        'mime_type',
        'size',
        'hash',
        'metadata'
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array'
    ];

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function getThumbnailUrl(string $size): ?string
    {
        $metadata = $this->metadata ?? [];
        if (isset($metadata['thumbnails'][$size])) {
            return Storage::disk('public')->url($metadata['thumbnails'][$size]);
        }
        return null;
    }
}
