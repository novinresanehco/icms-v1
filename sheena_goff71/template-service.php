<?php

namespace App\Core\Template;

class TemplateService
{
    private RenderEngine $renderer;
    private SecurityValidator $validator;
    private CacheService $cache;

    public function renderTemplate(string $name, array $data): string
    {
        $cacheKey = "template.$name." . md5(serialize($data));
        
        return $this->cache->remember($cacheKey, fn() => 
            $this->validator->validate(
                $this->renderer->render($name, $this->validator->sanitizeData($data))
            )
        );
    }

    public function renderContent(array $content): string
    {
        return $this->renderer->renderBlock([
            'view' => 'content',
            'data' => $this->validator->sanitizeData($content)
        ]);
    }

    public function renderMedia(array $media): string
    {
        return $this->renderer->renderBlock([
            'view' => 'media',
            'data' => $this->validator->sanitizeMedia($media)
        ]);
    }
}

class RenderEngine
{
    private array $blocks = [];
    private array $templates = [];

    public function render(string $name, array $data): string
    {
        $template = $this->templates[$name] ?? throw new TemplateNotFoundException();
        return $this->processTemplate($template, $data);
    }

    public function renderBlock(array $config): string
    {
        $block = $this->blocks[$config['view']] ?? throw new BlockNotFoundException();
        return $block($config['data']);
    }

    private function processTemplate(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{([\w.]+)\}\}/',
            fn($m) => $data[$m[1]] ?? '',
            $template
        );
    }
}

class SecurityValidator
{
    private array $allowedTags = [
        'div', 'p', 'span', 'h1', 'h2', 'h3', 'img'
    ];

    public function validate(string $content): string
    {
        return strip_tags($content, $this->allowedTags);
    }

    public function sanitizeData(array $data): array
    {
        return array_map([$this, 'sanitizeValue'], $data);
    }

    public function sanitizeMedia(array $media): array
    {
        return array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'url' => filter_var($item['url'], FILTER_SANITIZE_URL),
                'type' => $this->sanitizeValue($item['type']),
                'title' => $this->sanitizeValue($item['title'])
            ];
        }, $media);
    }

    private function sanitizeValue($value): string
    {
        return is_string($value) ? htmlspecialchars($value) : '';
    }
}
