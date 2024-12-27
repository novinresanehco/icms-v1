<?php

namespace App\Core\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\User;

class Media extends Model
{
    protected $fillable = [
        'path',
        'filename',
        'mime_type',
        'size',
        'metadata',
        'user_id',
        'audit_trail'
    ];

    protected $casts = [
        'metadata' => 'array',
        'audit_trail' => 'array',
        'size' => 'integer'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrl(): string
    {
        return Storage::disk(config('media.disk'))->url($this->path);
    }

    public function getMetadataValue(string $key, $default = null)
    {
        return data_get($this->metadata, $key, $default);
    }
}
