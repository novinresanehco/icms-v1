<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'type',
        'user_id',
        'parent_id',
        'template',
        'order'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    public static array $rules = [
        'title' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:contents,slug',
        'content' => 'required|string',
        'status' => 'required|string|in:draft,published,archived',
        'type' => 'required|string|in:page,post,product',
        'user_id' => 'required|integer|exists:users,id',
        'parent_id' => 'nullable|integer|exists:contents,id',
        'template' => 'nullable|string|max:255',
        'order' => 'nullable|integer'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function meta(): HasMany
    {
        return $this->hasMany(ContentMeta::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function getMetaValue(string $key, mixed $default = null): mixed
    {
        $meta = $this->meta()->where('key', $key)->first();
        return $meta ? $meta->value : $default;
    }

    public function setMetaValue(string $key, mixed $value): void
    {
        $this->meta()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}