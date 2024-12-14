<?php

namespace App\Core\Cache\Models;

use Illuminate\Database\Eloquent\Model;

class CacheEntry extends Model
{
    protected $fillable = [
        'key',
        'hits',
        'last_accessed_at'
    ];

    protected $casts = [
        'hits' => 'integer',
        'last_accessed_at' => 'datetime'
    ];
}
