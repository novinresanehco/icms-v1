<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use App\Core\Security\SecurityService;
use App\Core\Cache\CacheService;
use App\Core\Exceptions\TemplateException;

class TemplateEngine
{
    private SecurityService $security;
    private CacheService $cache;
    private array $registeredComponents = [];

    public function __construct(
        SecurityService $security,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function render(string $template, array $data = []): string
    {
        try {
            // Validate template access
            $this->security->validateTemplateAccess($template);

            // Get cached if available
            $cacheKey = $this->getCacheKey($template, $data);
            return $this->cache->remember($cacheKey, function() use ($template, $data) {
                // Sanitize data
                $sanitizedData = $this->security->sanitizeTemplateData($data);
                
                // Render with error boundary
                return $this->renderSecurely($template, $sanitizedData);
            });

        } catch (\Throwable $e) {
            throw new TemplateException("Template rendering failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function renderSecurely(string $template, array $data): string
    {
        return View::make("templates.$template", $data)
            ->render();
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }
}

class ContentDisplay
{
    private TemplateEngine $engine;
    private SecurityService $security;

    public function __construct(
        TemplateEngine $engine,
        SecurityService $security
    ) {
        $this->engine = $engine;
        $this->security = $security;
    }

    public function display(string $contentType, $content): string
    {
        // Validate content access
        $this->security->validateContentAccess($content);

        // Get appropriate template
        $template = $this->getContentTemplate($contentType);

        // Render with content
        return $this->engine->render($template, [
            'content' => $content,
            'type' => $contentType
        ]);
    }

    protected function getContentTemplate(string $type): string
    {
        return match ($type) {
            'article' => 'content.article',
            'page' => 'content.page',
            'gallery' => 'content.gallery',
            default => throw new TemplateException("Unknown content type: $type")
        };
    }
}

class MediaGallery
{
    private TemplateEngine $engine;
    private SecurityService $security;

    public function __construct(
        TemplateEngine $engine,
        SecurityService $security
    ) {
        $this->engine = $engine;
        $this->security = $security;
    }

    public function render(array $media, array $options = []): string
    {
        // Validate media access
        foreach ($media as $item) {
            $this->security->validateMediaAccess($item);
        }

        // Apply optimization for media display
        $optimizedMedia = array_map(
            fn($item) => $this->optimizeMediaItem($item, $options),
            $media
        );

        return $this->engine->render('media.gallery', [
            'media' => $optimizedMedia,
            'options' => $options
        ]);
    }

    protected function optimizeMediaItem($media, array $options): array
    {
        return [
            'src' => $this->security->sanitizeUrl($media['src']),
            'thumbnail' => $this->generateThumbnail($media['src'], $options),
            'alt' => $this->security->sanitizeString($media['alt'] ?? ''),
            'title' => $this->security->sanitizeString($media['title'] ?? '')
        ];
    }

    protected function generateThumbnail(string $src, array $options): string
    {
        // Thumbnail generation logic here
        return $src; // Placeholder
    }
}

class UIComponentRegistry
{
    private array $components = [];
    private SecurityService $security;

    public function __construct(SecurityService $security)
    {
        $this->security = $security;
    }

    public function register(string $name, string $template): void
    {
        $this->security->validateComponentRegistration($name, $template);
        $this->components[$name] = $template;
    }

    public function render(string $name, array $data = []): string
    {
        if (!isset($this->components[$name])) {
            throw new TemplateException("Component not found: $name");
        }

        return View::make($this->components[$name], $this->security->sanitizeComponentData($data))
            ->render();
    }
}
