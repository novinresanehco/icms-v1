<?php

namespace App\Models;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'requires_2fa',
        'last_login_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'requires_2fa' => 'boolean'
    ];

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'author_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'uploader_id');
    }
}

class Content extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'author_id',
        'published_at',
        'checksum'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array'
    ];

    protected static function booted()
    {
        static::creating(function ($content) {
            $content->slug = Str::slug($content->title);
            $content->checksum = app(SecurityManager::class)->generateChecksum($content->toArray());
        });

        static::updating(function ($content) {
            if ($content->isDirty('title')) {
                $content->slug = Str::slug($content->title);
            }
            $content->checksum = app(SecurityManager::class)->generateChecksum($content->toArray());
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class)
            ->withPivot('order', 'caption')
            ->orderBy('order');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where('published_at', '<=', now());
    }
}

class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'filename',
        'path',
        'type',
        'size',
        'mime_type',
        'metadata',
        'uploader_id',
        'checksum'
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer'
    ];

    protected static function booted()
    {
        static::creating(function ($media) {
            $media->checksum = hash_file('sha256', storage_path($media->path));
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class)
            ->withPivot('order', 'caption');
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }
}

class Role extends Model
{
    protected $fillable = ['name', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions->contains('name', $permission);
    }
}

class Permission extends Model
{
    protected $fillable = ['name', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('model');
    }
}
