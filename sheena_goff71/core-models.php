<?php namespace App\Models;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Support\Facades\{Hash, Cache};

class User extends Model {
    use SoftDeletes;
    protected $hidden = ['password'];
    protected $casts = ['active' => 'boolean'];
    protected static $securityFields = ['email', 'password'];

    public function setPasswordAttribute($value) {
        $this->attributes['password'] = Hash::make($value);
    }

    public function roles() {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }

    public function contents() {
        return $this->hasMany(Content::class);
    }

    public function hasPermission($permission) {
        return Cache::tags(['permissions', 'user:'.$this->id])
            ->remember('perm:'.$permission, 3600, function() use ($permission) {
                return $this->roles()
                    ->whereJsonContains('permissions', $permission)
                    ->exists();
            });
    }
}

class Content extends Model {
    use SoftDeletes;
    protected $fillable = ['title', 'content', 'status'];
    protected $casts = ['meta' => 'array'];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function categories() {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function media() {
        return $this->morphToMany(Media::class, 'mediable');
    }

    protected static function boot() {
        parent::boot();
        static::creating(function ($content) {
            Cache::tags(['content'])->flush();
        });
        static::updating(function ($content) {
            Cache::tags(['content:'.$content->id])->flush();
        });
    }
}

class Media extends Model {
    use SoftDeletes;
    protected $fillable = ['path', 'mime_type', 'size', 'meta'];
    protected $casts = ['meta' => 'array'];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function contents() {
        return $this->morphedByMany(Content::class, 'mediable');
    }
}

class Template extends Model {
    protected $fillable = ['name', 'path', 'content'];
    protected $casts = ['active' => 'boolean'];

    protected static function boot() {
        parent::boot();
        static::saved(function ($template) {
            Cache::tags(['templates'])->flush();
        });
    }
}

class Category extends Model {
    protected $fillable = ['name', 'slug', 'description'];

    public function contents() {
        return $this->morphedByMany(Content::class, 'categorizable');
    }

    public function children() {
        return $this->hasMany(Category::class, 'parent_id');
    }
}

class Role extends Model {
    protected $fillable = ['name', 'permissions'];
    protected $casts = ['permissions' => 'array'];

    public function users() {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }
}

class AuditLog extends Model {
    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'changes'];
    protected $casts = ['changes' => 'array'];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public static function log($action, $entity, $changes) {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'changes' => $changes,
            'ip_address' => request()->ip()
        ]);
    }
}
