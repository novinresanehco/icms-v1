<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Template\Interfaces\{ThemeManagerInterface, AssetManagerInterface};

class ThemeManager implements ThemeManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private AssetManagerInterface $assets;
    private array $themes = [];
    private ?string $activeTheme = null;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        AssetManagerInterface $assets
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->assets = $assets;
    }

    public function register(string $name, Theme $theme): void
    {
        $this->validateTheme($name, $theme);
        
        $this->themes[$name] = $theme;
        $this->cache->invalidate(['themes', $name]);
    }

    public function activate(string $name): void
    {
        if (!isset($this->themes[$name])) {
            throw new ThemeException("Unknown theme: {$name}");
        }

        $theme = $this->themes[$name];
        
        $this->security->executeInContext(function() use ($theme) {
            $this->assets->publishThemeAssets($theme);
            $this->activeTheme = $theme->getName();
            $this->cache->invalidate('active_theme');
        });
    }

    public function getActive(): Theme
    {
        if (!$this->activeTheme) {
            throw new ThemeException('No active theme');
        }

        return $this->themes[$this->activeTheme];
    }

    private function validateTheme(string $name, Theme $theme): void
    {
        if (!$this->security->validateResource($theme->getPath())) {
            throw new ThemeException("Invalid theme path: {$name}");
        }

        foreach ($theme->getTemplates() as $template) {
            if (!$this->security->validateFile($template)) {
                throw new ThemeException("Invalid theme template: {$template}"); 
            }
        }
    }
}

class Theme
{
    private string $name;
    private string $path;
    private array $templates;
    private array $assets;
    private array $config;

    public function __construct(
        string $name,
        string $path,
        array $templates,
        array $assets,
        array $config = []
    ) {
        $this->name = $name;
        $this->path = $path;
        $this->templates = $templates;
        $this->assets = $assets;
        $this->config = $config;
    }

    public function getName(): string 
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }
    
    public function getTemplates(): array
    {
        return $this->templates;
    }

    public function getAssets(): array 
    {
        return $this->assets;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}

class AssetManager implements AssetManagerInterface 
{
    private SecurityManagerInterface $security;
    private string $publicPath;

    public function __construct(
        SecurityManagerInterface $security,
        string $publicPath
    ) {
        $this->security = $security;
        $this->publicPath = $publicPath;
    }

    public function publishThemeAssets(Theme $theme): void
    {
        foreach ($theme->getAssets() as $source => $target) {
            $this->publishAsset($source, $target);
        }
    }

    private function publishAsset(string $source, string $target): void
    {
        $sourcePath = $source;
        $targetPath = $this->publicPath . '/' . $target;

        if (!$this->security->validateFile($sourcePath)) {
            throw new AssetException("Invalid asset source: {$source}");
        }

        if (!$this->security->validatePath($targetPath)) {
            throw new AssetException("Invalid asset target: {$target}");
        }

        if (!copy($sourcePath, $targetPath)) {
            throw new AssetException("Failed to publish asset: {$source}");
        }
    }
}

class TemplateInheritanceManager
{
    private array $parents = [];
    private array $blocks = [];

    public function extend(string $parent, array $blocks): Template
    {
        $this->validateParent($parent);
        $this->validateBlocks($blocks);

        return new Template($parent, $blocks);
    }

    public function registerParent(string $name, string $template): void
    {
        if (!file_exists($template)) {
            throw new TemplateException("Parent template not found: {$template}");
        }

        $this->parents[$name] = $template;
    }

    private function validateParent(string $parent): void
    {
        if (!isset($this->parents[$parent])) {
            throw new TemplateException("Unknown parent template: {$parent}");
        }
    }

    private function validateBlocks(array $blocks): void
    {
        foreach ($blocks as $name => $content) {
            if (!is_string($content)) {
                throw new TemplateException("Invalid block content: {$name}");
            }
        }
    }
}