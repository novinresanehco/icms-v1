// app/Core/Widget/Types/AbstractWidget.php
<?php

namespace App\Core\Widget\Types;

use App\Core\Widget\Contracts\WidgetInterface;
use App\Core\Widget\Models\Widget;

abstract class AbstractWidget implements WidgetInterface
{
    protected array $settings = [];
    protected array $defaultSettings = [];

    public function __construct(protected Widget $widget)
    {
        $this->settings = array_merge(
            $this->defaultSettings,
            $widget->settings ?? []
        );
    }

    public function getType(): string
    {
        return $this->widget->type;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    abstract public function render(): string;
    abstract public function validate(): bool;
}

// app/Core/Widget/Types/ContentWidget.php
<?php

namespace App\Core\Widget\Types;

use App\Core\Widget\Types\AbstractWidget;

class ContentWidget extends AbstractWidget
{
    protected array $defaultSettings = [
        'show_title' => true,
        'show_date' => true,
        'max_items' => 5
    ];

    public function validate(): bool
    {
        return isset($this->settings['max_items'])
            && is_int($this->settings['max_items'])
            && $this->settings['max_items'] > 0;
    }

    public function render(): string
    {
        if (!$this->validate()) {
            return '';
        }

        $content = $this->getContent();
        return view('widgets.content', [
            'widget' => $this->widget,
            'settings' => $this->settings,
            'content' => $content
        ])->render();
    }

    protected function getContent(): array
    {
        return \Cache::remember(
            "widget_content_{$this->widget->id}",
            now()->addHours(1),
            fn() => $this->fetchContent()
        );
    }

    protected function fetchContent(): array
    {
        return [];
    }
}

// app/Core/Widget/Types/MenuWidget.php
<?php

namespace App\Core\Widget\Types;

use App\Core\Widget\Types\AbstractWidget;

class MenuWidget extends AbstractWidget
{
    protected array $defaultSettings = [
        'depth' => 1,
        'show_description' => false
    ];

    public function validate(): bool
    {
        return isset($this->settings['depth'])
            && is_int($this->settings['depth'])
            && $this->settings['depth'] > 0;
    }

    public function render(): string
    {
        if (!$this->validate()) {
            return '';
        }

        $menu = $this->getMenu();
        return view('widgets.menu', [
            'widget' => $this->widget,
            'settings' => $this->settings,
            'menu' => $menu
        ])->render();
    }

    protected function getMenu(): array
    {
        return \Cache::remember(
            "widget_menu_{$this->widget->id}",
            now()->addHours(1),
            fn() => $this->fetchMenu()
        );
    }

    protected function fetchMenu(): array
    {
        return [];
    }
}

// app/Core/Widget/Types/SocialWidget.php
<?php

namespace App\Core\Widget\Types;

use App\Core\Widget\Types\AbstractWidget;

class SocialWidget extends AbstractWidget
{
    protected array $defaultSettings = [
        'show_icons' => true,
        'target' => '_blank'
    ];

    public function validate(): bool
    {
        return true;
    }

    public function render(): string
    {
        $social = $this->getSocial();
        return view('widgets.social', [
            'widget' => $this->widget,
            'settings' => $this->settings,
            'social' => $social
        ])->render();
    }

    protected function getSocial(): array
    {
        return \Cache::remember(
            "widget_social_{$this->widget->id}",
            now()->addHours(1),
            fn() => $this->fetchSocial()
        );
    }

    protected function fetchSocial(): array
    {
        return [];
    }
}