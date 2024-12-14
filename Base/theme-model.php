<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Theme extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'version',
        'author',
        'screenshot',
        'settings',
        'is_active',
        'is_system',
        'required_plugins'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'required_plugins' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    public static array $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:themes,slug',
        'description' => 'nullable|string',
        'version' => 'required|string|max:50',
        'author' => 'nullable|string|max:255',
        'screenshot' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        'settings' => 'nullable|array',
        'is_active' => 'boolean',
        'required_plugins' => 'nullable|array'
    ];

    public function customizations(): HasMany
    {
        return $this->hasMany(ThemeCustomization::class);
    }

    public function getCustomization(string $key, mixed $default = null): mixed
    {
        $customization = $this->customizations()
            ->where('key', $key)
            ->first();

        return $customization ? $customization->value : $default;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function getScreenshotUrl(): ?string
    {
        return $this->screenshot ? Storage::url($this->screenshot) : null;
    }

    public function requiresPlugin(string $plugin): bool
    {
        return in_array($plugin, $this->required_plugins ?? []);
    }

    public function getViewPath(string $view): string
    {
        return "themes.{$this->slug}.{$view}";
    }

    public function getAssetPath(string $asset): string
    {
        return "themes/{$this->slug}/assets/{$asset}";
    }
}