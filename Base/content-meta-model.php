<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContentMeta extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_id',
        'key',
        'value'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public static array $rules = [
        'content_id' => 'required|integer|exists:contents,id',
        'key' => 'required|string|max:255',
        'value' => 'required|string'
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}