<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaThumbnail extends Model
{
    protected $fillable = [
        'media_id',
        'size',
        'width',
        'height',
        'path'
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer'
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
