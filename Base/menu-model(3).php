<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\{Model, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Menu extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'location',
        'description',
        'status',
        'language'
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->whereNull('parent_id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }
}
