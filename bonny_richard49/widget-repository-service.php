// app/Core/Widget/Repositories/WidgetRepository.php
<?php

namespace App\Core\Widget\Repositories;

use App\Core\Widget\Models\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use App\Core\Base\Repositories\BaseRepository;

class WidgetRepository extends BaseRepository
{
    protected function model(): string
    {
        return Widget::class;
    }

    public function findActive(int $id): ?Widget
    {
        return Cache::tags(['widgets'])->remember(
            "widget-{$id}-active",
            config('widgets.cache_ttl', 3600),
            fn() => $this->model->where('status', 'active')->find($id)
        );
    }

    public function getActiveWidgets(): Collection
    {
        return Cache::tags(['widgets'])->remember(
            'widgets-active',
            config('widgets.cache_ttl', 3600),
            fn() => $this->model->where('status', 'active')
                               ->orderBy('order')
                               ->get()
        );
    }

    public function getByType(string $type): Collection
    {
        return Cache::tags(['widgets', "widgets-type-{$type}"])->remember(
            "widgets-type-{$type}-list",
            config('widgets.cache_ttl', 3600),
            fn() => $this->model->where('type', $type)
                               ->where('status', 'active')
                               ->orderBy('order')
                               ->get()
        );
    }

    public function updateOrder(array $order): bool
    {
        try {
            foreach ($order as $position => $widgetId) {
                $this->model->where('id', $widgetId)
                           ->update(['order' => $position]);
            }

            $this->clearCache();
            return true;

        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function clearCache(): void
    {
        Cache::tags(['widgets'])->flush();
    }
}

// app/Core/Widget/Services/WidgetService.php
<?php

namespace App\Core\Widget\Services;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\Repositories\WidgetRepository;
use App\Core\Widget\Factories\WidgetFactory;
use App\Core\Widget\Processors\WidgetProcessor;
use App\Core\Widget\Renderers\WidgetRenderer;
use App\Core\Widget\Events\WidgetCreatedEvent;
use App\Core\Widget\Events\WidgetUpdatedEvent;
use App\Core\Widget\Events\WidgetDeletedEvent;
use App\Core\Widget\Exceptions\WidgetException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Contracts\Auth\Authenticatable;

class WidgetService
{
    public function __construct(
        private WidgetRepository $repository,
        private WidgetFactory $factory,
        private WidgetProcessor $processor,
        private WidgetRenderer $renderer
    ) {}

    public function create(array $data): Widget
    {
        DB::beginTransaction();
        try {
            $widget = $this->factory->create($data);
            Event::dispatch(new WidgetCreatedEvent($widget));
            DB::commit();
            return $widget;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to create widget: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Widget
    {
        DB::beginTransaction();
        try {
            $widget = $this->repository->find($id);
            if (!$widget) {
                throw new WidgetException("Widget not found: {$id}");
            }

            $widget->update($data);
            Event::dispatch(new WidgetUpdatedEvent($widget));
            
            $this->repository->clearCache();
            DB::commit();
            
            return $widget;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to update widget: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $widget = $this->repository->find($id);
            if (!$widget) {
                throw new WidgetException("Widget not found: {$id}");
            }

            $widget->delete();
            Event::dispatch(new WidgetDeletedEvent($widget));
            
            $this->repository->clearCache();
            DB::commit();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to delete widget: {$e->getMessage()}", 0, $e);
        }
    }

    public function render(int $id, ?Authenticatable $user = null): string
    {
        $widget = $this->repository->findActive($id);
        if (!$widget) {
            return '';
        }

        return $this->renderer->render($widget, $user);
    }

    public function renderAll(?Authenticatable $user = null): string
    {
        $widgets = $this->repository->getActiveWidgets();
        return $this->renderer->renderCollection($widgets, $user);
    }

    public function renderType(string $type, ?Authenticatable $user = null): string
    {
        $widgets = $this->repository->getByType($type);
        return $this->renderer->renderCollection($widgets, $user);
    }

    public function updateOrder(array $order): bool
    {
        try {
            return $this->repository->updateOrder($order);
        } catch (\Exception $e) {
            throw new WidgetException("Failed to update widget order: {$e->getMessage()}", 0, $e);
        }
    }
}

// app/Core/Widget/Events/WidgetCreatedEvent.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue