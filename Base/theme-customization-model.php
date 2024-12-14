<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ThemeCustomization extends Model
{
    use HasFactory;

    protected $fillable = [
        'theme_id',
        'key',
        'value'
    ];

    protected $casts = [
        'value' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public static array $rules = [
        'theme_id' => 'required|integer|exists:themes,id',
        'key' => 'required|string|max:255',
        'value' => 'required|json'
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}