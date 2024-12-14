<?php

namespace App\Repositories;

use App\Models\Theme;
use App\Repositories\Contracts\ThemeRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ThemeRepository extends BaseRepository implements ThemeRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'author'];
    protected array $filterableFields = ['status', 'type'];

    public function activate(int $id): bool
    {
        try {
            // Deactivate current active theme
            $this->model->where('is_active', true)->update(['is_active' => false]);
            
            // Activate new theme
            $result = $this->update($id, [
                'is_active' => true,
                'activated_at' => now()
            ]);
            
            Cache::tags(['themes'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error activating theme: ' . $e->getMessage());
            return false;
        }
    }

    public function getActive(): ?Theme
    {
        return Cache::tags(['themes'])->remember('theme.active', 3600, function() {
            return $this->model
                ->where('is_active', true)
                ->first();
        });
    }

    public function compileAssets(Theme $theme): bool
    {
        try {
            $compiler = app('App\Services\Theme\AssetCompiler');
            return $compiler->compile($theme);
        } catch (\Exception $e) {
            \Log::error('Error compiling theme assets: ' . $e->getMessage());
            return false;
        }
    }

    public function getCustomizations(Theme $theme): array
    {
        return Cache::tags(['themes'])->remember("theme.customizations.{$theme->id}", 3600, function() use ($theme) {
            return [
                'colors' => $theme->settings['colors'] ?? [],
                'fonts' => $theme->settings['fonts'] ?? [],
                'layouts' => $theme->settings['layouts'] ?? [],
                'custom_css' => $theme->settings['custom_css'] ?? '',
                'custom_js' => $theme->settings['custom_js'] ?? ''
            ];
        });
    }

    public function updateCustomizations(Theme $theme, array $customizations): bool
    {
        try {
            $settings = $theme->settings;
            $settings['colors'] = $customizations['colors'] ?? $settings['colors'] ?? [];
            $settings['fonts'] = $customizations['fonts'] ?? $settings['fonts'] ?? [];
            $settings['layouts'] = $customizations['layouts'] ?? $settings['layouts'] ?? [];
            $settings['custom_css'] = $customizations['custom_css'] ?? $settings['custom_css'] ?? '';
            $settings['custom_js'] = $customizations['custom_js'] ?? $settings['custom_js'] ?? '';

            $result = $this->update($theme->id, [
                'settings' => $settings,
                'last_customized_at' => now()
            ]);

            if ($result) {
                Cache::tags(['themes'])->flush();
                return $this->compileAssets($theme);
            }

            return false;
        } catch (\Exception $e) {
            \Log::error('Error updating theme customizations: ' . $e->getMessage());
            return false;
        }
    }
}
