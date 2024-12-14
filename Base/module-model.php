<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Module extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'version',
        'priority',
        'is_active',
        'settings',
        'dependencies'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'settings' => 'array',
        'dependencies' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($module) {
            if (empty($module->slug)) {
                $module->slug = Str::slug($module->name);
            }
        });
    }

    public function isInstalled(): bool
    {
        return file_exists($this->getModulePath());
    }

    public function getModulePath(): string
    {
        return base_path('modules/' . $this->slug);
    }

    public function hasDependency(string $moduleSlug): bool
    {
        return in_array($moduleSlug, $this->dependencies ?? []);
    }
}
