// app/Core/Widget/Renderers/AbstractWidgetRenderer.php
<?php

namespace App\Core\Widget\Renderers;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\Contracts\WidgetRendererInterface;
use App\Core\Widget\Events\WidgetRendered;
use Illuminate\Support\Facades\View;

abstract class AbstractWidgetRenderer implements WidgetRendererInterface
{
    public function render(Widget $widget): string
    {
        try {
            $data = $this->prepareData($widget);
            $view = $this->getView($widget);
            
            $rendered = View::make($view, $data)->render();
            
            event(new WidgetRendered($widget, $data));
            
            return $rendered;
        } catch (\Throwable $e) {
            report($e);
            return $this->renderError($widget, $e);
        }
    }

    abstract protected function prepareData(Widget $widget): array;
    
    protected function getView(Widget $widget): string
    {
        return "widgets.{$widget->type}";
    }

    protected function renderError(Widget $widget, \Throwable $e): string
    {
        if (config('app.debug')) {
            return "<!-- Widget Error ({$widget->id}): {$e->getMessage()} -->";
        }
        return '';
    }
}

// app/Core/Widget/Renderers/ContentWidgetRenderer.php
<?php

namespace App\Core\Widget\Renderers;

use App\Core\Widget\Models\Widget;

class ContentWidgetRenderer extends AbstractWidgetRenderer
{
    protected function prepareData(Widget $widget): array
    {
        return [
            'widget' => $widget,
            'items' => $this->getContentItems($widget),
            'settings' => $widget->settings
        ];
    }

    protected function getContentItems(Widget $widget): array
    {
        $maxItems = $widget->settings['max_items'] ?? 5;
        return [];
    }

    protected function getView(Widget $widget): string
    {
        return 'widgets.content';
    }
}

// app/Core/Widget/Renderers/MenuWidgetRenderer.php
<?php

namespace App\Core\Widget\Renderers;

use App\Core\Widget\Models\Widget;

class MenuWidgetRenderer extends AbstractWidgetRenderer
{
    protected function prepareData(Widget $widget): array
    {
        return [
            'widget' => $widget,
            'menu_items' => $this->getMenuItems($widget),
            'settings' => $widget->settings
        ];
    }

    protected function getMenuItems(Widget $widget): array
    {
        $depth = $widget->settings['depth'] ?? 1;
        return [];
    }

    protected function getView(Widget $widget): string
    {
        return 'widgets.menu';
    }
}

// app/Core/Widget/Renderers/SocialWidgetRenderer.php
<?php

namespace App\Core\Widget\Renderers;

use App\Core\Widget\Models\Widget;

class SocialWidgetRenderer extends AbstractWidgetRenderer
{
    protected function prepareData(Widget $widget): array
    {
        return [
            'widget' => $widget,
            'networks' => $this->getSocialNetworks($widget),
            'settings' => $widget->settings
        ];
    }

    protected function getSocialNetworks(Widget $widget): array
    {
        return [];
    }

    protected function getView(Widget $widget): string
    {
        return 'widgets.social';
    }
}