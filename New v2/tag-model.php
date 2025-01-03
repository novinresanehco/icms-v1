<?php

namespace App\Core\Tagging;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\Content\Content;
use App\Core\User\User;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'type', 
        'metadata',
        'user_id',
        'audit_trail'
    ];

    protected $casts = [
        'metadata' => 'array',
        'audit_trail' => 'array'
    ];

    public function content(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_tags')
                    ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
