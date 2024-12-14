<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kalnoy\Nestedset\NodeTrait;

class Category extends Model
{
    use SoftDeletes, NodeTrait;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        '_lft',
        '_rgt',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function activeContents(): BelongsToMany
    {
        return $this->contents()
            ->where('status', true)
            ->whereNotNull('published_at');
    }
}
