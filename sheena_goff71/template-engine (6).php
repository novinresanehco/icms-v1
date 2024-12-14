<?php

namespace App\Core\Template;

class TemplateEngine
{
    private CacheManager $cache;
    private ContentRenderer $renderer;
    private MediaHandler $media;
    
    public function __construct(
        CacheManager $cache,
        ContentRenderer $renderer,
        MediaHandler $media
    ) {
        $this->cache = $cache;
        $this->renderer = $renderer;
        $this->media = $media;
    }

    public function renderContent(string $template, array $data): string
    {
        $cacheKey = $this->generateCacheKey($template, $data);
        
        return $this->cache->remember($cacheKey, function() use ($template, $data) {
            $content = $this->renderer->parse($template, $data);
            $content = $this->processMediaTags($content);
            return $this->renderer->compile($content);
        });
    }

    private function processMediaTags(string $content): string
    {
        return preg_replace_callback('/\{media\:([0-9]+)\}/', function($matches) {
            return $this->media->render((int)$matches[1]);
        }, $content);
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }
}

class ContentRenderer
{
    private array $components = [];

    public function parse(string $template, array $data): string
    {
        $template = $this->validateTemplate($template);
        $data = $this->sanitizeData($data);
        return $this->parseTemplate($template, $data);
    }

    public function compile(string $content): string
    {
        foreach ($this->components as $component) {
            $content = $component->process($content);
        }
        return $content;
    }

    public function registerComponent(string $name, ComponentInterface $component): void
    {
        $this->components[$name] = $component;
    }

    private function parseTemplate(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            fn($m) => $data[$m[1]] ?? '',
            $template
        );
    }
}

class MediaHandler
{
    private MediaRepository $repository;
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    public function render(int $mediaId): string
    {
        $media = $this->repository->find($mediaId);
        if (!$media || !in_array($media->type, $this->allowedTypes)) {
            return '';
        }

        return $this->generateMediaTag($media);
    }

    private function generateMediaTag(Media $media): string
    {
        return sprintf(
            '<img src="%s" alt="%s" class="media-item" loading="lazy">',
            $media->url,
            htmlspecialchars($media->title)
        );
    }
}

interface ComponentInterface
{
    public function process(string $content): string;
}
