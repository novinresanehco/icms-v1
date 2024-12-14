<?php

namespace App\View\Components;

use Illuminate\View\Component;
use App\Core\Security\SecurityValidator;

abstract class BaseTemplateComponent extends Component
{
    protected SecurityValidator $security;
    protected array $defaultConfig = [];

    public function __construct(SecurityValidator $security)
    {
        $this->security = $security;
    }

    protected function validateConfig(array $config): array
    {
        return $this->security->validateComponentConfig(
            static::class,
            array_merge($this->defaultConfig, $config)
        );
    }
}

class ContentSection extends BaseTemplateComponent
{
    public string $content;
    public array $config;

    public function render()
    {
        $config = $this->validateConfig([
            'tag' => $this->attributes->get('tag', 'div'),
            'class' => $this->attributes->get('class', ''),
            'secure' => true
        ]);

        return view('components.content-section', [
            'content' => $this->security->sanitizeContent($this->content),
            'config' => $config
        ]);
    }
}

class MediaDisplay extends BaseTemplateComponent
{
    public array $media;
    public array $options;

    public function render()
    {
        $options = $this->validateConfig([
            'layout' => $this->options['layout'] ?? 'grid',
            'columns' => $this->options['columns'] ?? 3,
            'lazy' => $this->options['lazy'] ?? true,
            'secure' => true
        ]);

        $processedMedia = array_map(
            fn($item) => $this->processMediaItem($item),
            $this->media
        );

        return view('components.media-display', [
            'media' => $processedMedia,
            'options' => $options
        ]);
    }

    private function processMediaItem(array $item): array
    {
        return [
            'src' => $this->security->sanitizeUrl($item['src']),
            'alt' => $this->security->sanitizeString($item['alt'] ?? ''),
            'title' => $this->security->sanitizeString($item['title'] ?? ''),
            'type' => $this->security->validateMediaType($item['type'] ?? 'image')
        ];
    }
}

class LayoutContainer extends BaseTemplateComponent
{
    public array $layout;
    
    public function render()
    {
        $config = $this->validateConfig([
            'template' => $this->layout['template'] ?? 'default',
            'sections' => $this->layout['sections'] ?? [],
            'secure' => true
        ]);

        return view('components.layout-container', [
            'sections' => $this->processSections($config['sections']),
            'template' => $config['template']
        ]);
    }

    private function processSections(array $sections): array
    {
        return array_map(
            fn($section) => [
                'type' => $this->security->validateSectionType($section['type']),
                'content' => $this->security->sanitizeContent($section['content']),
                'config' => $this->security->validateSectionConfig($section['config'] ?? [])
            ],
            $sections
        );
    }
}

class SecurityBoundary extends BaseTemplateComponent
{
    public array $context;
    
    public function render()
    {
        $validatedContext = $this->validateConfig([
            'permissions' => $this->context['permissions'] ?? [],
            'scope' => $this->context['scope'] ?? 'default',
            'secure' => true
        ]);

        if (!$this->security->validateContextBoundary($validatedContext)) {
            return '';
        }

        return view('components.security-boundary', [
            'context' => $validatedContext,
        ]);
    }
}
