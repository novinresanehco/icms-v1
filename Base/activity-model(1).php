<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    protected $fillable = [
        'type',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties'
    ];

    protected $casts = [
        'properties' => 'array'
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function getIconAttribute(): string
    {
        return match($this->type) {
            'created' => 'plus-circle',
            'updated' => 'edit',
            'deleted' => 'trash',
            'restored' => 'refresh-cw',
            'login' => 'log-in',
            'logout' => 'log-out',
            default => 'activity'
        };
    }

    public function getColorAttribute(): string
    {
        return match($this->type) {
            'created' => 'green',
            'updated' => 'blue',
            'deleted' => 'red',
            'restored' => 'yellow',
            'login' => 'purple',
            'logout' => 'gray',
            default => 'gray'
        };
    }
}
