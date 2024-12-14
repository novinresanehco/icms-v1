<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'file_name',
        'disk',
        'mime_type',
        'size',
        'collection',
        'type',
        'path',
        'url',
        'metadata',
        'conversions',
        'responsive_images',
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array',
        'conversions' => 'array',
        'responsive_images' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getFullPath(): string
    {
        return storage_path('app/' . $this->disk . '/' . $this->path);
    }

    public function getUrl(?string $conversion = null): string
    {
        if ($conversion && isset($this->conversions[$conversion])) {
            return $this->conversions[$conversion]['url'];
        }

        return $this->url;
    }
}
