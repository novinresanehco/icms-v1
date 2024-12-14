<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'original_name',
        'path',
        'disk',
        'mime_type',
        'size',
        'fileable_type',
        'fileable_id',
        'metadata',
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getFullPathAttribute(): string
    {
        return $this->path . '/' . $this->name;
    }
}
