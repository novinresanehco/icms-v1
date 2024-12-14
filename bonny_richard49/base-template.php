<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{View, Cache};

class TemplateManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplate($template, $data),
            ['context' => 'template_render']
        );
    }

    public function compileTemplate(string $template): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processCompilation($template),
            ['context' => 'template_compile']
        );
    }

    protected function processTemplate(string $template, array $data): string
    {
        $key = $this->getCacheKey($template, $data);
        
        if ($this->config['cache_enabled'] && Cache::has($key)) {
            return Cache::get($key);
        }

        $compiled = View::make($template, $data)->render();
        
        if ($this->config['cache_enabled']) {
            Cache::put($key, $compiled, $this->config['cache_duration']);
        }

        return $compiled;
    }

    protected function processCompilation(string $template): string
    {
        return View::make($template)->render();
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template_' . md5($template . serialize($data));
    }
}

class ThemeManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function activateTheme(string $theme): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processThemeActivation($theme),
            ['context' => 'theme_activation']
        );
    }

    protected function processThemeActivation(string $theme): bool
    {
        if (!$this->validateTheme($theme)) {
            throw new ThemeException('Invalid theme');
        }

        $this->setActiveTheme($theme);
        $this->clearThemeCache();
        
        return true;
    }

    protected function validateTheme(string $theme): bool
    {
        return file_exists($this->getThemePath($theme));
    }

    protected function setActiveTheme