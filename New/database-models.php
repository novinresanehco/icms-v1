<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Security\Traits\EncryptsAttributes;

class User extends Model
{
    use EncryptsAttributes;
    
    protected $fillable = ['name', 'email', 'password', 'role'];
    protected $hidden = ['password'];
    protected $encrypted = ['email'];

    public function content(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role->permissions->contains('name', $permission);
    }

    public function can(string $permission): bool
    {
        return $this->hasPermission($permission);
    }
}

class Content extends Model 
{
    use EncryptsAttributes;

    protected $fillable = ['title', 'body', 'status', 'category_id'];
    protected $encrypted = ['body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }
}

class Media extends Model
{
    protected $fillable = ['path', 'type', 'size'];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function content(): HasMany
    {
        return $this->hasMany(Content::class);
    }
}

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    public function content(): BelongsToMany
    {
        return $this->belongsToMany(Content::class);
    }
}

class Role extends Model
{
    protected $fillable = ['name'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

class Permission extends Model
{
    protected $fillable = ['name'];

    public function roles(): BelongsToMany  
    {
        return $this->belongsToMany(Role::class);
    }
}

trait EncryptsAttributes
{
    private function encryptAttribute($value)
    {
        return app(SecurityManager::class)->encryptData($value);
    }

    private function decryptAttribute($value)
    {
        return app(SecurityManager::class)->decryptData($value);
    }

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        
        if (in_array($key, $this->encrypted ?? [])) {
            return $this->decryptAttribute($value);
        }
        
        return $value;
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->encrypted ?? [])) {
            $value = $this->encryptAttribute($value);
        }
        
        return parent::setAttribute($key, $value);
    }
}
