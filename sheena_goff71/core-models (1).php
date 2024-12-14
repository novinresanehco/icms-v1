<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsToMany};

class User extends Authenticatable
{
    protected $fillable = ['email', 'password', 'is_active'];
    protected $hidden = ['password'];
    protected $casts = ['is_active' => 'boolean'];

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'created_by');
    }
}

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'permission'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

class UserSession extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at'];
    protected $casts = ['expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

class Content extends Model
{
    protected $fillable = ['title', 'body', 'status', 'category_id', 'created_by'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'content_media');
    }
}

class ContentVersion extends Model
{
    protected $fillable = ['content_id', 'data', 'created_by'];
    protected $casts = ['data' => 'array'];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

class Category extends Model
{
    protected $fillable = ['name', 'slug'];

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }
}

class Media extends Model
{
    protected $fillable = ['filename', 'mime_type', 'path', 'uploaded_by'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_media');
    }
}

class ActivityLog extends Model
{
    protected $fillable = ['type', 'loggable_type', 'loggable_id', 'user_id', 'data'];
    protected $casts = ['data' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function loggable()
    {
        return $this->morphTo();
    }
}

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }
        });
    }
}
