<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Template extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'theme',
        'layout',
        'is_active',
        'is_default',
        'settings'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'settings' => 'array',
        'layout' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($template) {
            if (empty($template->slug)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }

    public function sections(): HasMany
    {
        return $this->hasMany(TemplateSection::class)->orderBy('order');
    }

    public function getSectionByKey(string $key): ?TemplateSection
    {
        return $this->sections->firstWhere('key', $key);
    }

    public function compile(array $data = []): string
    {
        return view($this->getViewPath(), array_merge([
            'template' => $this,
            'settings' => $this->settings
        ], $data))->render();
    }

    public function getViewPath(): string
    {
        return "themes.{$this->theme}.templates.{$this->slug}";
    }
}
