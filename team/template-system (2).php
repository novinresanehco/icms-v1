<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Contracts\{TemplateManagerInterface, ThemeRepositoryInterface};
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private ThemeRepositoryInterface $themes;
    private CacheManager $cache;
    private array $globalData = [];

    public function __construct(
        SecurityManager $security,
        ThemeRepositoryInterface $themes,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->themes = $themes;
        $this->cache = $cache;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTemplateRender($template, $data),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    private function executeTemplateRender(string $template, array $data): string
    {
        // Get template content
        $templateContent = $this->getTemplate($template);

        // Merge global and local data
        $mergedData = array_merge($this->globalData, $data);

        // Validate data against template requirements
        $this->validateTemplateData($template, $mergedData);

        // Process template components
        $processedContent = $this->processComponents($templateContent, $mergedData);

        // Apply security filters
        $secureContent = $this->applySecurityFilters($processedContent);

        return $secureContent;
    }

    public function registerComponent(string $name, callable $renderer): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeComponentRegistration($name, $renderer),
            ['action' => 'register_component', 'name' => $name]
        );
    }

    private function executeComponentRegistration(string $name, callable $renderer): void
    {
        if (isset($this->components[$name])) {
            throw new TemplateException("Component '$name' already registered");
        }

        $this->components[$name] = $renderer;
        $this->cache->tags(['components'])->forget($name);
    }

    private function getTemplate(string $name): string
    {
        return $this->cache->tags(['templates'])->remember(
            "template:$name",
            3600,
            fn() => $this->loadTemplate($name)
        );
    }

    private function loadTemplate(string $name): string
    {
        $theme = $this->themes->getActiveTheme();
        $template = $theme->getTemplate($name);

        if (!$template) {
            throw new TemplateException("Template '$name' not found");
        }

        return $template->content;
    }

    private function validateTemplateData(string $template, array $data): void
    {
        $requirements = $this->getTemplateRequirements($template);

        foreach ($requirements as $field => $rules) {
            if (!isset($data[$field]) && $rules['required'] ?? false) {
                throw new TemplateException("Required field '$field' missing");
            }

            if (isset($data[$field])) {
                $this->validateField($field, $data[$field], $rules);
            }
        }
    }

    private function validateField(string $field, $value, array $rules): void
    {
        foreach ($rules as $rule => $param) {
            switch ($rule) {
                case 'type':
                    if (gettype($value) !== $param) {
                        throw new TemplateException("Field '$field' must be of type '$param'");
                    }
                    break;
                case 'pattern':
                    if (!preg_match($param, $value)) {
                        throw new TemplateException("Field '$field' format invalid");
                    }
                    break;
                case 'sanitize':
                    if ($param === true) {
                        $value = $this->sanitizeValue($value);
                    }
                    break;
            }
        }
    }

    private function processComponents(string $content, array $data): string
    {
        return preg_replace_callback(
            '/<component\s+name="([^"]+)"[^>]*>(.*?)<\/component>/s',
            function($matches) use ($data) {
                return $this->renderComponent($matches[1], $matches[2], $data);
            },
            $content
        );
    }

    private function renderComponent(string $name, string $content, array $data): string
    {
        if (!isset($this->components[$name])) {
            throw new TemplateException("Component '$name' not found");
        }

        return $this->cache->tags(['components'])->remember(
            "component:$name:" . md5($content . serialize($data)),
            3600,
            fn() => ($this->components[$name])($content, $data)
        );
    }

    private function applySecurityFilters(string $content): string
    {
        // Apply XSS protection
        $content = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Apply content security policy
        $content = $this->applyCSP($content);

        // Remove potentially dangerous elements
        $content = $this->sanitizeHTML($content);

        return $content;
    }

    private function applyCSP(string $content): string
    {
        // Add CSP headers
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");
        
        return $content;
    }

    private function sanitizeHTML(string $content): string
    {
        // Remove potentially dangerous HTML elements and attributes
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', storage_path('app/purifier'));
        $purifier = new HTMLPurifier($config);
        
        return $purifier->purify($content);
    }

    public function addGlobalData(array $data): void
    {
        $this->globalData = array_merge($this->globalData, $data);
    }

    private function getTemplateRequirements(string $template): array
    {
        return $this->cache->tags(['templates'])->remember(
            "template:$template:requirements",
            3600,
            fn() => $this->themes->getTemplateRequirements($template)
        );
    }
}
