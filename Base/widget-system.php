<?php

namespace App\Contracts;

interface WidgetInterface
{
    /**
     * Get the widget's unique identifier
     */
    public function getId(): int;
    
    /**
     * Get the widget's type
     */
    public function getType(): string;
    
    /**
     * Get the widget's configuration
     */
    public function getConfig(): array;
    
    /**
     * Render the widget
     */
    public function render(): string;
    
    /**
     * Validate the widget's configuration
     */
    public function validate(): bool;
    
    /**
     * Get the widget's cache key
     */
    public function getCacheKey(): string;
    
    /**
     * Get the widget's cache duration in seconds
     */
    public function getCacheDuration(): int;
}

// Base Widget Implementation
namespace App\Widgets;

use App\Contracts\WidgetInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

abstract class AbstractWidget implements WidgetInterface
{
    protected int $id;
    protected string $type;
    protected array $config;
    protected int $cacheDuration = 3600; // Default 1 hour

    public function getId(): int 
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getCacheKey(): string
    {
        return "widget_{$this->type}_{$this->id}";
    }

    public function getCacheDuration(): int
    {
        return $this->cacheDuration;
    }

    public function render(): string
    {
        return Cache::remember($this->getCacheKey(), $this->getCacheDuration(), function() {
            return $this->renderWidget();
        });
    }

    abstract protected function renderWidget(): string;
    
    abstract public function validate(): bool;
}

// Widget Manager Service
namespace App\Services;

use App\Contracts\WidgetInterface;
use App\Repositories\WidgetAreaRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WidgetManager
{
    protected WidgetAreaRepository $areaRepository;
    protected Collection $registeredWidgets;

    public function __construct(WidgetAreaRepository $areaRepository) 
    {
        $this->areaRepository = $areaRepository;
        $this->registeredWidgets = collect();
    }

    public function registerWidget(string $type, string $widgetClass): void
    {
        if (!is_subclass_of($widgetClass, WidgetInterface::class)) {
            throw new \InvalidArgumentException("Widget class must implement WidgetInterface");
        }
        
        $this->registeredWidgets->put($type, $widgetClass);
    }

    public function renderArea(string $slug): string
    {
        $area = $this->areaRepository->findBySlug($slug);
        
        if (!$area->is_active) {
            return '';
        }

        $widgets = $area->widgets
            ->where('is_active', true)
            ->sortBy('order')
            ->map(function ($widget) {
                return $this->instantiateWidget($widget);
            });

        return $widgets
            ->filter()
            ->map(function (WidgetInterface $widget) {
                try {
                    return $widget->render();
                } catch (\Exception $e) {
                    report($e);
                    return '';
                }
            })
            ->join('');
    }

    protected function instantiateWidget($widgetData): ?WidgetInterface
    {
        $widgetClass = $this->registeredWidgets->get($widgetData->type);
        
        if (!$widgetClass) {
            return null;
        }

        try {
            return new $widgetClass($widgetData->id, $widgetData->config ?? []);
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }

    public function clearWidgetCache(int $widgetId): void
    {
        $widget = $this->instantiateWidget($widgetId);
        if ($widget) {
            Cache::forget($widget->getCacheKey());
        }
    }
}
