<?php

namespace App\Core\Data\Models;

use Illuminate\Database\Eloquent\Model;

class CriticalData extends Model
{
    protected $table = 'critical_data';
    
    protected $fillable = [
        'key',
        'data',
        'version'
    ];

    protected $casts = [
        'data' => 'array',
        'version' => 'integer'
    ];
}

class User extends Model
{
    protected $fillable = [
        'username',
        'password',
        'role'
    ];

    protected $hidden = [
        'password'
    ];

    public function hasPermission(string $permission): bool
    {
        $permissions = [
            'admin' => ['*'],
            'editor' => ['content.*'],
            'user' => ['content.view']
        ];

        return in_array('*', $permissions[$this->role] ?? []) ||
               in_array($permission, $permissions[$this->role] ?? []);
    }
}

class AuthLog extends Model
{
    protected $table = 'auth_logs';
    
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'username', 
        'ip_address',
        'details',
        'created_at'
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime'
    ];
}
