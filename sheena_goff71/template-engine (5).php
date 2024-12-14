<?php

namespace App\Core\Template;

class TemplateEngine
{
    private ValidatorService $validator;
    private CacheManager $cache;
    private array $layouts = [];

    public function render(string $template, array $data, string $layout = 'default'): string
    {
        $cacheKey = $this->getCacheKey($template, $data, $layout);
        
        return $this->cache->remember($cacheKey, function() use ($template, $data, $layout) {
            $content = $this->validator->clean($template);
            $content = $this->processTemplate($content, $data);
            return $this->applyLayout($content, $layout);
        });
    }

    public function registerLayout(string $name, callable $renderer): void
    {
        $this->layouts[$name] = $renderer;
    }

    private function processTemplate(string $content, array $data): string
    {
        $content = $this->replaceVariables($content, $data);
        $content = $this->processComponents($content, $data);
        return $content;
    }

    private function getCacheKey(string $template, array $data, string $layout): string
    {
        return 'template.' . md5($template . serialize($data) . $layout);
    }

    private function applyLayout(string $content, string $layout): string
    {
        if (!isset($this->layouts[$layout])) {
            $layout = 'default';
        }
        return ($this->layouts[$layout])($content);
    }

    private function replaceVariables(string $content, array $data): string
    {
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            fn($m) => $data[$m[1]] ?? '',
            $content
        );
    }
}

class ValidatorService
{
    private array $allowedTags = [
        'p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'a', 'img', 'strong', 'em'
    ];

    public function clean(string $content): string
    {
        $content = strip_tags($content, $this->allowedTags);
        $content = $this->escapeScripts($content);
        return $content;
    }

    private function escapeScripts(string $content): string
    {
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
    }
}
