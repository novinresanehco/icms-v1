<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'file_name',
        'mime_type',
        'path',
        'disk',
        'size',
        'alt_text',
        'title',
        'description',
        'folder_id',
        'user_id'
    ];

    protected $casts = [
        'size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $appends = [
        'url',
        'thumbnail_url',
        'medium_url'
    ];

    public static array $rules = [
        'name' => 'required|string|max:255',
        'file_name' => 'required|string|max:255',
        'mime_type' => 'required|string|max:255',
        'path' => 'required|string|max:255',
        'disk' => 'required|string|max:255',
        'size' => 'required|integer',
        'alt_text' => 'nullable|string|max:255',
        'title' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'folder_id' => 'nullable|integer|exists:media_folders,id',
        'user_id' => 'required|integer|exists:users,id'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->isImage()) {
            return null;
        }
        return Storage::disk($this->disk)->url($this->getThumbnailPath());
    }

    public function getMediumUrlAttribute(): ?string
    {
        if (!$this->isImage()) {
            return null;
        }
        return Storage::disk($this->disk)->url($this->getMediumPath());
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    protected function getThumbnailPath(): string
    {
        return $this->getVariantPath('thumb');
    }

    protected function getMediumPath(): string
    {
        return $this->getVariantPath('medium');
    }

    protected function getVariantPath(string $variant): string
    {
        $info = pathinfo($this->path);
        return $info['dirname'] . '/' . $info['filename'] . "_{$variant}." . $info['extension'];
    }
}