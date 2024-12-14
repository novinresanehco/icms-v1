<?php

namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagVersion extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tag_id',
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'version',
        'created_by',
        'changes',
        'reverted_from'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the tag that owns the version.
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * Get the user that created the version.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the reverted from version.
     */
    public function revertedFrom(): BelongsTo
    {
        return $this->belongsTo(TagVersion::class, 'reverted_from');
    }

    /**
     * Check if this version is the latest for its tag.
     */
    public function isLatest(): bool
    {
        return $this->version === $this->tag->versions()->max('version');
    }

    /**
     * Get a summary of changes in this version.
     */
    public function getChangesSummary(): string
    {
        $summary = [];
        foreach ($this->changes as $field => $change) {
            $summary[] = ucfirst($field) . " changed from '{$change['old']}' to '{$change['new']}'";
        }
        return implode(', ', $summary);
    }
}
