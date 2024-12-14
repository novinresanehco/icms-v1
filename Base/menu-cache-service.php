<?php

namespace App\Services;

use App\Models\Menu;
use Illuminate\Support\Facades\Cache;

class MenuCacheService
{
    public function getMenu(string $location): ?Menu
    {
        return Cache::tags(['menus'])->remember(
            "menu.location.{$location}",
            config('cache.ttl', 3600),
            fn() => Menu::with(['items' => function($query) {
                $query->where('is_active', true)
                    ->orderBy('order');
            }])->where('location', $location)
              ->where('is_active', true)
              ->first()
        );
    }

    public function clearMenuCache(string $location): void
    {
        Cache::tags(['menus'])->flush();
        Cache::forget("menu.location.{$location}");
    }

    public function clearAllMenuCache(): void
    {
        Cache::tags(['menus'])->flush();
    }
}
