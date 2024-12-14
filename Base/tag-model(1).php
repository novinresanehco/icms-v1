<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class);
    }

    public function activeContents(): BelongsToMany
    {
        return $this->contents()
            ->where('status', true)
            ->whereNotNull('published_at');
    }
}
