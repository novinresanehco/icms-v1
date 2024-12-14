<?php

namespace App\Core\Template\Components;

abstract class BaseUIComponent
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    
    public function __construct(SecurityManager $security, CacheManager $cache)
    {
        $this->security = $security;
        $this->cache = $cache;
    }

    abstract public function render(): string;
    abstract public function validate(): bool;
    
    protected function getCacheKey(): string
    {
        return 'component.' . static::class . '.' . md5(serialize($this));
    }
}

class NavigationComponent extends BaseUIComponent
{
    private array $items;

    public function setItems(array $items): void
    {
        $this->items = array_map(fn($item) => 
            $this->security->sanitizeMenuItem($item), 
            $items
        );
    }

    public function render(): string
    {
        return $this->cache->remember($this->getCacheKey(), function() {
            return view('components.navigation', [
                'items' => $this->items
            ])->render();
        });
    }

    public function validate(): bool
    {
        return !empty($this->items) && 
               $this->security->validateNavigation($this->items);
    }
}

class ContentGridComponent extends BaseUIComponent
{
    private array $items;
    private string $layout;

    public function render(): string
    {
        if (!$this->validate()) {
            throw new ComponentException('Invalid grid configuration');
        }

        return $this->cache->remember($this->getCacheKey(), function() {
            return view('components.grid', [
                'items' => $this->items,
                'layout' => $this->layout
            ])->render();
        });
    }

    public function validate(): bool
    {
        return !empty($this->items) && 
               in_array($this->layout, ['grid', 'list', 'masonry']);
    }
}
