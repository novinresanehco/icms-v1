<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Theme;
use App\Models\ThemeCustomization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Repositories\Interfaces\ThemeRepositoryInterface;

class ThemeRepository implements ThemeRepositoryInterface
{
    private const CACHE_PREFIX = 'theme:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Theme $model,
        private readonly ThemeCustomization $customizationModel
    ) {}

    public function findById(int $id): ?Theme
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with('customizations')->find($id)
        );
    }

    public function findBySlug(string $slug): ?Theme
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->with('customizations')->where('slug', $slug)->first()
        );
    }

    public function getActive(): ?Theme
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'active',
            self::CACHE_TTL,
            fn () => $this->model->with('customizations')
                ->where('is_active', true)
                ->first()
        );
    }

    public function create(array $data): Theme
    {
        $theme = $this->model->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'version' => $data['version'],
            'author' => $data['author'] ?? null,
            'screenshot' => $this->handleScreenshot($data),
            'settings' => $data['settings'] ?? [],
            'is_active' => $data['is_active'] ?? false,
            'is_system' => $data['is_system'] ?? false,
            'required_plugins' => $data['required_plugins'] ?? []
        ]);

        if (isset($data['customizations'])) {
            $this->createCustomizations($theme->id, $data['customizations']);
        }

        $this->clearCache();

        return $theme;
    }

    public function update(int $id, array $data): bool
    {
        $theme = $this->findById($id);
        
        if (!$theme) {
            return false;
        }

        if (isset($data['screenshot'])) {
            $data['screenshot'] = $this->handleScreenshot($data);
            if ($theme->screenshot) {
                Storage::delete($theme->screenshot);
            }
        }

        $updated = $theme->update([
            'name' => $data['name'] ?? $theme->name,
            'slug' => $data['slug'] ?? $theme->slug,
            'description' => $data['description'] ?? $theme->description,
            'version' => $data['version'] ?? $theme->version,
            'author' => $data['author'] ?? $theme->author,
            'screenshot' => $data['screenshot'] ?? $theme->screenshot,
            'settings' => $data['settings'] ?? $theme->settings,
            'is_active' => $data['is_active'] ?? $theme->is_active,
            'required_plugins' => $data['required_plugins'] ?? $theme->required_plugins
        ]);

        if (isset($data['customizations'])) {
            $this->updateCustomizations($id, $data['customizations']);
        }

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $theme = $this->findById($id);
        
        if (!$theme || $theme->is_system) {
            return false;
        }

        if ($theme->screenshot) {
            Storage::delete($theme->screenshot);
        }

        $this->customizationModel->where('theme_id', $id)->delete();
        $deleted = $theme->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function getAll(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            fn () => $this->model->with('customizations')->get()
        );
    }

    public function activate(int $id): bool
    {
        $theme = $this->findById($id);
        
        if (!$theme) {
            return false;
        }

        // Deactivate current theme
        $this->model->where('is_active', true)
            ->update(['is_active' => false]);

        // Activate new theme
        $activated = $theme->update(['is_active' => true]);

        if ($activated) {
            $this->clearCache();
        }

        return $activated;
    }

    public function updateCustomization(int $themeId, string $key, mixed $value): bool
    {
        $theme = $this->findById($themeId);
        
        if (!$theme) {
            return false;
        }

        $this->customizationModel->updateOrCreate(
            [
                'theme_id' => $themeId,
                'key' => $key
            ],
            [
                'value' => $value
            ]
        );

        $this->clearCache();

        return true;
    }

    protected function handleScreenshot(array $data): ?string
    {
        if (!isset($data['screenshot'])) {
            return null;
        }

        $file = $data['screenshot'];
        $path = 'themes/screenshots';
        return $file->store($path);
    }

    protected function createCustomizations(int $themeId, array $customizations): void
    {
        foreach ($customizations as $key => $value) {
            $this->customizationModel->create([
                'theme_id' => $themeId,
                'key' => $key,
                'value' => $value
            ]);
        }
    }

    protected function updateCustomizations(int $themeId, array $customizations): void
    {
        foreach ($customizations as $key => $value) {
            $this->customizationModel->updateOrCreate(
                [
                    'theme_id' => $themeId,
                    'key' => $key
                ],
                [
                    'value' => $value
                ]
            );
        }
    }

    protected function clearCache(): void
    {
        $keys = ['all', 'active'];
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}