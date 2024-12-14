<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'path',
        'type',
        'mime_type',
        'size',
        'user_id',
        'description',
        'alt_text'
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function meta(): HasMany
    {
        return $this->hasMany(MediaMeta::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }
}
