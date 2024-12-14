<?php

namespace App\Core\Repositories;

use App\Models\Theme;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class ThemeRepository extends AdvancedRepository
{
    protected $model = Theme::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getActive(): Theme
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('theme.active', function() {
                return $this->model
                    ->where('active', true)
                    ->firstOrFail();
            });
        });
    }

    public function activate(Theme $theme): void
    {
        $this->executeTransaction(function() use ($theme) {
            $this->model->where('active', true)->update(['active' => false]);
            $theme->update(['active' => true]);
            $this->cache->forget('theme.active');
        });
    }

    public function getAvailable(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('themes.available', function() {
                return $this->model
                    ->with('customizations')
                    ->orderBy('name')
                    ->get();
            });
        });
    }

    public function updateCustomization(Theme $theme, array $customizations): void
    {
        $this->executeTransaction(function() use ($theme, $customizations) {
            $theme->customizations()->delete();
            
            foreach ($customizations as $key => $value) {
                $theme->customizations()->create([
                    'key' => $key,
                    'value' => $value
                ]);
            }
            
            $this->cache->forget(['theme.active', 'themes.available']);
        });
    }
}
