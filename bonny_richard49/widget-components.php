// app/Core/Widget/Processors/WidgetProcessor.php
<?php

namespace App\Core\Widget\Processors;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\Resolvers\WidgetSettingsResolver;
use App\Core\Widget\Resolvers\WidgetPermissionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use App\Core\Widget\Events\WidgetProcessedEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class WidgetProcessor
{
    public function __construct(
        private WidgetSettingsResolver $settingsResolver,
        private WidgetPermissionResolver $permissionResolver
    ) {}

    public function process(Widget $widget, ?Authenticatable $user = null): ?array
    {
        if ($user && !$this->permissionResolver->resolve($widget, $user)) {
            return null;
        }

        return Cache::tags(['widgets', "widget-{$widget->id}"])->remember(
            $this->getCacheKey($widget, $user),
            config('widgets.cache_ttl', 3600),
            fn() => $this->processWidget($widget, $user)
        );
    }

    private function processWidget(Widget $widget, ?Authenticatable $user): array
    {
        $settings = $this->settingsResolver->resolve($widget);
        $data = $this->processWidgetData($widget, $settings);

        Event::dispatch(new WidgetProcessedEvent($widget, $user));

        return [
            'id' => $widget->id,
            'type' => $widget->type,
            'title' => $widget->title,
            'settings' => $settings,
            'data' => $data,
            'processed_at' => now()->toIso8601String()
        ];
    }

    private function processWidgetData(Widget $widget, array $settings): array
    {
        return match($widget->type) {
            'content' => $this->processContentWidget($widget, $settings),
            'menu' => $this->processMenuWidget($widget, $settings),
            'social' => $this->processSocialWidget($widget, $settings),
            default => []
        };
    }

    private function getCacheKey(Widget $widget, ?Authenticatable $user): string
    {
        $userKey = $user ? "user-{$user->id}" : 'guest';
        return "widget-{$widget->id}-{$userKey}";
    }

    private function processContentWidget(Widget $widget, array $settings): array
    {
        // Implementation for content widget processing
        return [];
    }

    private function processMenuWidget(Widget $widget, array $settings): array
    {
        // Implementation for menu widget processing
        return [];
    }

    private function processSocialWidget(Widget $widget, array $settings): array
    {
        // Implementation for social widget processing
        return [];
    }
}

// app/Core/Widget/Factories/WidgetFactory.php
<?php

namespace App\Core\Widget\Factories;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\Resolvers\WidgetTypeResolver;
use App\Core\Widget\Exceptions\WidgetCreationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WidgetFactory
{
    public function __construct(
        private WidgetTypeResolver $typeResolver
    ) {}

    public function create(array $data): Widget
    {
        $this->validateData($data);

        try {
            DB::beginTransaction();

            $widgetClass = $this->typeResolver->resolve($data['type']);
            $widget = new $widgetClass();
            
            $widget->fill($this->prepareData($data));
            $widget->save();

            if (isset($data['metadata'])) {
                $this->processMetadata($widget, $data['metadata']);
            }

            DB::commit();
            return $widget;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetCreationException(
                "Failed to create widget: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function validateData(array $data): void
    {
        $validator = Validator::make($data, [
            'type' => 'required|string',
            'title' => 'required|string|max:255',
            'settings' => 'sometimes|array',
            'metadata' => 'sometimes|array',
            'permissions' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            throw new WidgetCreationException(
                "Invalid widget data: " . $validator->errors()->first()
            );
        }
    }

    private function prepareData(array $data): array
    {
        return [
            'type' => $data['type'],
            'title' => $data['title'],
            'settings' => $data['settings'] ?? [],
            'permissions' => $data['permissions'] ?? [],
            'status' => $data['status'] ?? 'active',
            'order' => $data['order'] ?? 0
        ];
    }

    private function processMetadata(Widget $widget, array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $widget->metadata()->create([
                'key' => $key,
                'value' => $value
            ]);
        }
    }
}

// app/Core/Widget/Renderers/WidgetRenderer.php
<?php

namespace App\Core\Widget\Renderers;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\Processors\WidgetProcessor;
use App\Core\Widget\Resolvers\WidgetViewResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\View;
use App\Core\Widget\Events\WidgetRenderedEvent;

class WidgetRenderer
{
    public function __construct(
        private WidgetProcessor $processor,
        private WidgetViewResolver $viewResolver
    ) {}

    public function render(Widget $widget, ?Authenticatable $user = null): string
    {
        $processedData = $this->processor->process($widget, $user);
        
        if ($processedData === null) {
            return '';
        }

        try {
            $view = $this->viewResolver->resolve($widget);
            $rendered = View::make($view, [
                'widget' => $widget,
                'data' => $processedData
            ])->render();

            event(new WidgetRenderedEvent($widget, $user));

            return $rendered;

        } catch (\Throwable $e) {
            report($e);
            return $this->renderError($widget, $e);
        }
    }

    public function renderCollection(iterable $widgets, ?Authenticatable $user = null): string
    {
        $output = '';
        foreach ($widgets as $widget) {
            $output .= $this->render($widget, $user);
        }
        return $output;
    }

    private function renderError(Widget $widget, \Throwable $error): string
    {
        if (config('app.debug')) {
            return sprintf(
                '<!-- Widget Error (%s): %s -->',
                $widget->id,
                $error->getMessage()
            );
        }

        return '';
    }
}

// app/Core/Widget/Events/WidgetProcessedEvent.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetProcessedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Widget $widget,
        public ?Authenticatable $user
    ) {}
}

// app/Core/Widget/Events/WidgetRenderedEvent.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetRenderedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Widget $widget,
        public ?Authenticatable $user
    ) {}
}