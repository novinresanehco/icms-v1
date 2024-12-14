<?php

namespace App\Core\UI;

use Illuminate\View\Component;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityValidator;

abstract class BaseUIComponent extends Component
{
    protected SecurityValidator $security;
    protected array $defaultConfig = [];
    
    public function __construct(SecurityValidator $security)
    {
        $this->security = $security;
    }

    protected function validateProps(array $props): array
    {
        return $this->security->validateComponentProps(
            static::class,
            $props,
            $this->getValidationRules()
        );
    }

    protected function getCacheKey(array $props): string
    {
        return 'component:' . static::class . ':' . md5(serialize($props));
    }

    abstract protected function getValidationRules(): array;
}

class ContentCard extends BaseUIComponent
{
    public function render()
    {
        $props = $this->validateProps([
            'title' => $this->title,
            'content' => $this->content,
            'image' => $this->image ?? null,
            'metadata' => $this->metadata ?? []
        ]);

        return Cache::remember(
            $this->getCacheKey($props),
            3600,
            fn() => view('components.content-card', $props)
        );
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'image' => 'nullable|string|url',
            'metadata' => 'array'
        ];
    }
}

class MediaGalleryComponent extends BaseUIComponent
{
    protected array $defaultConfig = [
        'columns' => 3,
        'thumbnailSize' => [200, 200],
        'lazyLoad' => true
    ];

    public function render()
    {
        $props = $this->validateProps([
            'items' => $this->items,
            'config' => array_merge(
                $this->defaultConfig,
                $this->config ?? []
            )
        ]);

        return Cache::remember(
            $this->getCacheKey($props),
            3600,
            fn() => view('components.media-gallery', [
                'items' => $this->optimizeMediaItems($props['items']),
                'config' => $props['config']
            ])
        );
    }

    protected function optimizeMediaItems(array $items): array
    {
        return array_map(function($item) {
            return [
                'src' => $this->security->sanitizeUrl($item['src']),
                'thumbnail' => $this->generateThumbnail($item['src']),
                'alt' => $this->security->sanitizeString($item['alt'] ?? ''),
                'title' => $this->security->sanitizeString($item['title'] ?? '')
            ];
        }, $items);
    }

    protected function generateThumbnail(string $src): string
    {
        return $src; // Implement actual thumbnail generation
    }

    protected function getValidationRules(): array
    {
        return [
            'items' => 'required|array',
            'items.*.src' => 'required|string|url',
            'items.*.alt' => 'nullable|string',
            'items.*.title' => 'nullable|string',
            'config' => 'array'
        ];
    }
}

class TemplateLayout extends BaseUIComponent
{
    public function render()
    {
        $props = $this->validateProps([
            'template' => $this->template,
            'sections' => $this->sections ?? [],
            'metadata' => $this->metadata ?? []
        ]);

        return view('layouts.template', $props);
    }

    protected function getValidationRules(): array
    {
        return [
            'template' => 'required|string|exists:templates,name',
            'sections' => 'array',
            'metadata' => 'array'
        ];
    }
}

class ComponentRegistry
{
    private array $components = [];
    private SecurityValidator $security;

    public function __construct(SecurityValidator $security)
    {
        $this->security = $security;
    }

    public function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, BaseUIComponent::class)) {
            throw new \InvalidArgumentException("$class must extend BaseUIComponent");
        }

        $this->security->validateComponentRegistration($name, $class);
        $this->components[$name] = $class;
    }

    public function resolve(string $name): string
    {
        if (!isset($this->components[$name])) {
            throw new \InvalidArgumentException("Component not registered: $name");
        }

        return $this->components[$name];
    }
}
