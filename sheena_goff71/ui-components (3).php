<?php

namespace App\Core\Template\Components;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;

class UIComponentManager implements UIComponentInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private array $registeredComponents = [];

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function registerComponent(string $name, callable $renderer): void
    {
        $this->registeredComponents[$name] = $renderer;
    }

    public function render(string $component, array $props = []): string
    {
        if (!isset($this->registeredComponents[$component])) {
            throw new ComponentNotFoundException($component);
        }

        $this->security->validateComponentAccess($component);
        $this->validateProps($props);

        $cacheKey = "ui_component:{$component}:" . md5(serialize($props));

        return $this->cache->remember($cacheKey, function() use ($component, $props) {
            $renderer = $this->registeredComponents[$component];
            $output = $renderer($props);
            return $this->security->sanitizeHtml($output);
        });
    }

    private function validateProps(array $props): void
    {
        array_walk_recursive($props, function($value) {
            if (!$this->security->validateInput($value)) {
                throw new InvalidPropsException();
            }
        });
    }
}

class ComponentNotFoundException extends \RuntimeException {}
class InvalidPropsException extends \RuntimeException {}

// Critical UI Components Registration
$components->registerComponent('navigation', function($props) {
    return '<nav class="main-nav">...</nav>';
});

$components->registerComponent('pagination', function($props) {
    $currentPage = (int)($props['page'] ?? 1);
    $totalPages = (int)($props['total'] ?? 1);
    return "<div class='pagination'>...</div>";
});
