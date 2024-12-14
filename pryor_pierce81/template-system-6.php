<?php

namespace App\Template;

class TemplateEngine
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function render(string $template, array $data): string
    {
        return $this->executeSecure(function() use ($template, $data) {
            // Validate template and data
            $this->validator->validateTemplate($template);
            $this->validator->validateData($data);

            // Get cached if available
            $cacheKey = $this->getCacheKey($template, $data);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Process and render
            $processed = $this->processTemplate($template, $data);
            $rendered = $this->renderTemplate($processed, $data);
            
            // Cache result
            $this->cache->set($cacheKey, $rendered);
            
            return $rendered;
        });
    }

    private function processTemplate(string $template, array $data): string
    {
        $processed = $this->security->sanitizeTemplate($template);
        $processed = $this->injectSecurityHeaders($processed);
        $processed = $this->validateOutput($processed);
        return $processed;
    }

    private function renderTemplate(string $template, array $data): string
    {
        $data = $this->security->sanitizeData($data);
        return view($template, $data)->render();
    }

    private function executeSecure(callable $operation): string
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            $this->logger->logError('Template rendering failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new TemplateException('Rendering failed: ' . $e->getMessage());
        }
    }
}

class MediaGallery 
{
    private MediaManager $media;
    private SecurityManager $security;
    private ValidationService $validator;

    public function render(array $media, array $options = []): string
    {
        $this->validator->validateMediaItems($media);
        $this->validator->validateOptions($options);

        $items = array_map(function($item) {
            return $this->renderMediaItem($item);
        }, $media);

        return view('components.media-gallery', [
            'items' => $items,
            'options' => $this->security->sanitizeOptions($options)
        ])->render();
    }

    private function renderMediaItem(Media $media): array
    {
        return [
            'url' => $this->security->sanitizeUrl($media->url),
            'thumbnail' => $this->security->sanitizeUrl($media->thumbnail),
            'title' => $this->security->sanitizeText($media->title),
            'alt' => $this->security->sanitizeText($media->alt)
        ];
    }
}

class ComponentRegistry 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $components = [];

    public function register(string $name, callable $renderer): void
    {
        $this->validator->validateComponentName($name);
        $this->components[$name] = $renderer;
    }

    public function render(string $name, array $props = []): string
    {
        if (!isset($this->components[$name])) {
            throw new ComponentException("Component '$name' not found");
        }

        $props = $this->security->sanitizeProps($props);
        return $this->components[$name]($props);
    }
}

class UIComponents
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ComponentRegistry $registry;

    public function __construct()
    {
        $this->registerCoreComponents();
    }

    private function registerCoreComponents(): void
    {
        $this->registry->register('card', function($props) {
            return view('components.card', $props)->render();
        });

        $this->registry->register('button', function($props) {
            return view('components.button', $props)->render();
        });

        $this->registry->register('alert', function($props) {
            return view('components.alert', $props)->render();
        });
    }

    public function render(string $component, array $props = []): string
    {
        $this->validator->validateComponent($component);
        $this->validator->validateProps($props);

        return $this->registry->render($component, $props);
    }
}
