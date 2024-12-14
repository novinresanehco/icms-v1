<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Revision extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_id',
        'user_id',
        'title',
        'content',
        'metadata',
        'summary',
        'version'
    ];

    protected $casts = [
        'metadata' => 'array',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public static array $rules = [
        'content_id' => 'required|integer|exists:contents,id',
        'user_id' => 'required|integer|exists:users,id',
        'title' => 'required|string|max:255',
        'content' => 'required|string',
        'metadata' => 'nullable|array',
        'summary' => 'nullable|string',
        'version' => 'required|integer|min:1'
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getMetaValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }

    public function getDiff(Revision $other): array
    {
        return [
            'title' => $this->title !== $other->title,
            'content' => $this->content !== $other->content,
            'metadata' => $this->metadata !== $other->metadata
        ];
    }

    public function isLatest(): bool
    {
        return !$this->content->revisions()
            ->where('version', '>', $this->version)
            ->exists();
    }
}