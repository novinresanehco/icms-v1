<?php

namespace App\Core\Template;

use App\Core\Security\CoreSecurityManager;
use App\Core\Infrastructure\CacheSystem;
use Illuminate\Support\Facades\{View, File};

class TemplateManager
{
    private CoreSecurityManager $security;
    private CacheSystem $cache;
    private ThemeRegistry $themes;

    public function render(string $template, array $data): string
    {
        return $this->security->executeSecureOperation(
            fn() => $this->renderTemplate($template, $data),
            ['action' => 'template.render']
        );
    }

    private function renderTemplate(string $template, array $data): string
    {
        $cacheKey = "template:$template:" . md5(serialize($data));
        
        return $this->cache->remember(
            $cacheKey,
            fn() => View::make($template, $this->sanitizeData($data))->render()
        );
    }

    private function sanitizeData(array $data): array
    {
        return array_map(fn($value) => 
            is_string($value) ? htmlspecialchars($value) : $value, 
            $data
        );
    }
}

class ThemeRegistry
{
    private string $activeTheme;
    private array $themeConfig;

    public function setTheme(string $theme): void
    {
        if (!$this->themeExists($theme)) {
            throw new TemplateException("Theme not found: $theme");
        }
        
        $this->activeTheme = $theme;
        $this->loadThemeConfig($theme);
    }

    private function themeExists(string $theme): bool
    {
        return File::exists(resource_path("themes/$theme"));
    }

    private function loadThemeConfig(string $theme): void
    {
        $configPath = resource_path("themes/$theme/config.php");
        $this->themeConfig = File::exists($configPath) 
            ? require $configPath 
            : [];
    }
}

class ComponentRegistry
{
    private array $components = [];
    private CacheSystem $cache;

    public function register(string $name, callable $component): void
    {
        $this->components[$name] = $component;
        $this->cache->invalidate("component:$name");
    }

    public function render(string $name, array $props): string
    {
        if (!isset($this->components[$name])) {
            throw new TemplateException("Component not found: $name");
        }

        return $this->cache->remember(
            "component:$name:" . md5(serialize($props)),
            fn() => ($this->components[$name])($props)
        );
    }
}

class AdminTemplate
{
    private TemplateManager $templates;
    private ComponentRegistry $components;

    public function dashboard(array $data): string
    {
        return $this->templates->render('admin.dashboard', [
            'menu' => $this->components->render('admin-menu', []),
            'content' => $data['content'],
            'user' => $data['user']
        ]);
    }

    public function contentEditor(array $data): string
    {
        return $this->templates->render('admin.editor', [
            'content' => $data['content'],
            'controls' => $this->components->render('editor-controls', $data)
        ]);
    }
}

class TemplateException extends \Exception {}
