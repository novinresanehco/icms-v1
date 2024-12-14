<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    protected $guarded = ['id'];
    
    protected $casts = [
        'data' => 'encrypted:array',
        'metadata' => 'encrypted:array',
        'published_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function media()
    {
        return $this->hasMany(Media::class);
    }
}

class User extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'permissions' => 'encrypted:array',
        'metadata' => 'encrypted:array',
        'email_verified_at' => 'datetime',
    ];

    public function contents()
    {
        return $this->hasMany(Content::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}

class Role extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'permissions' => 'encrypted:array',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}

class Media extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'encrypted:array',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }
}

class Tag extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'encrypted:array',
    ];

    public function contents()
    {
        return $this->belongsToMany(Content::class);
    }
}
