<?php

namespace App\Core\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'is_public',
        'is_system',
        'metadata'
    ];

    protected $casts = [
        'value' => 'json',
        'is_public' => 'boolean',
        'is_system' => 'boolean',
        'metadata' => 'array'
    ];

    public function getValue($default = null)
    {
        return $this->value ?? $default;
    }

    public function setValue($value): void
    {
        $this->value = $this->castValue($value);
    }

    protected function castValue($value)
    {
        switch ($this->type) {
            case 'boolean':
                return (bool) $value;
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'array':
                return is_array($value) ? $value : [$value];
            default:
                return $value;
        }
    }

    public function scopeInGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }
}
