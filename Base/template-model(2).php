<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\{Model, Relations\HasMany, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Template extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'content',
        'type',
        'category',
        'status',
        'author_id',
        'variables',
        'settings',
        'version'
    ];

    protected $casts = [
        'variables' => 'array',
        'settings' => 'array'
    ];

    public function regions(): HasMany
    {
        return $this->hasMany(TemplateRegion::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
