<?php

namespace App\Core\Template;

class TemplateRegistry
{
    private array $templates = [];
    private array $components = [];
    private SecurityService $security;
    private CacheManager $cache;

    public function register(string $name, string $path): void 
    {
        $this->validatePath($path);
        $this->templates[$name] = $path;
    }

    public function registerComponent(string $name, ComponentInterface $component): void
    {
        $this->components[$name] = $component;
    }

    public function render(string $name, array $data): string 
    {
        return $this->cache->remember("template.$name", function() use ($name, $data) {
            $template = $this->loadTemplate($name);
            $processed = $this->processComponents($template, $data);
            return $this->security->sanitize($processed);
        });
    }

    private function processComponents(string $content, array $data): string 
    {
        foreach ($this->components as $key => $component) {
            $content = $component->process($content, $data);
        }
        return $content;
    }

    private function loadTemplate(string $name): string 
    {
        if (!isset($this->templates[$name])) {
            throw new TemplateException("Template not found: $name");
        }
        return file_get_contents($this->templates[$name]);
    }

    private function validatePath(string $path): void 
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new TemplateException("Invalid template path: $path");
        }
    }
}

interface ComponentInterface 
{
    public function process(string $content, array $data): string;
}

class MediaComponent implements ComponentInterface 
{
    private MediaService $media;

    public function process(string $content, array $data): string 
    {
        return preg_replace_callback('/\{media:(\d+)\}/', function($matches) {
            return $this->media->render((int)$matches[1]);
        }, $content);
    }
}

class GalleryComponent implements ComponentInterface 
{
    private MediaService $media;

    public function process(string $content, array $data): string 
    {
        if (!isset($data['gallery'])) return $content;

        $gallery = $this->renderGallery($data['gallery']);
        return str_replace('{gallery}', $gallery, $content);
    }

    private function renderGallery(array $items): string 
    {
        $output = '<div class="gallery grid">';
        foreach ($items as $item) {
            $output .= $this->renderItem($item);
        }
        $output .= '</div>';
        return $output;
    }

    private function renderItem(array $item): string 
    {
        return sprintf(
            '<div class="gallery-item"><img src="%s" alt="%s" loading="lazy"></div>',
            $item['url'],
            htmlspecialchars($item['title'])
        );
    }
}

class ContentComponent implements ComponentInterface 
{
    public function process(string $content, array $data): string 
    {
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            fn($m) => $data[$m[1]] ?? '',
            $content
        );
    }
}

class LayoutComponent implements ComponentInterface 
{
    private array $layouts = [];

    public function process(string $content, array $data): string 
    {
        $layout = $data['layout'] ?? 'default';
        if (!isset($this->layouts[$layout])) {
            $layout = 'default';
        }
        return str_replace('{content}', $content, $this->layouts[$layout]);
    }
}
