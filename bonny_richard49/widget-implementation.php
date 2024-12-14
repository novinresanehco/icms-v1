// app/Core/Widget/Contracts/WidgetRepositoryInterface.php
<?php

namespace App\Core\Widget\Contracts;

use App\Core\Widget\DTO\WidgetData;
use App\Core\Widget\Models\Widget;
use Illuminate\Support\Collection;

interface WidgetRepositoryInterface
{
    public function create(WidgetData $data): Widget;
    public function update(int $id, WidgetData $data): Widget;
    public function delete(int $id): bool;
    public function find(int $id): ?Widget;
    public function findByIdentifier(string $identifier): ?Widget;
    public function findByArea(string $area): Collection;
    public function getActive(): Collection;
    public function updateOrder(array $order): void;
    public function updateVisibility(int $id, array $rules): void;
}

// app/Core/Widget/Models/Widget.php
<?php

namespace App\Core\Widget\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Widget\Events\WidgetCreated;
use App\Core\Widget\Events\WidgetUpdated;
use App\Core\Widget\Events\WidgetDeleted;

class Widget extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'identifier',
        'type',
        'area',
        'settings',
        'order',
        'is_active',
        'cache_ttl',
        'visibility_rules',
        'permissions',
        'metadata'
    ];

    protected $casts = [
        'settings' => 'array',
        'visibility_rules' => 'array', 
        'permissions' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean'
    ];

    protected $dispatchesEvents = [
        'created' => WidgetCreated::class,
        'updated' => WidgetUpdated::class,
        'deleted' => WidgetDeleted::class
    ];
}

// app/Core/Widget/Repositories/WidgetRepository.php
<?php

namespace App\Core\Widget\Repositories;

use App\Core\Widget\Contracts\WidgetRepositoryInterface;
use App\Core\Widget\DTO\WidgetData;
use App\Core\Widget\Models\Widget;
use App\Core\Widget\Exceptions\WidgetNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WidgetRepository implements WidgetRepositoryInterface 
{
    private const CACHE_PREFIX = 'widget:';
    private const CACHE_TTL = 3600; // 1 hour

    public function create(WidgetData $data): Widget
    {
        $widget = new Widget();
        $this->mapDataToModel($widget, $data);
        $widget->save();

        $this->clearCache();
        
        return $widget;
    }

    public function update(int $id, WidgetData $data): Widget 
    {
        $widget = $this->find($id);
        
        if (!$widget) {
            throw new WidgetNotFoundException("Widget not found with ID: {$id}");
        }

        $this->mapDataToModel($widget, $data);
        $widget->save();

        $this->clearCache();
        
        return $widget;
    }

    public function delete(int $id): bool
    {
        $widget = $this->find($id);
        
        if (!$widget) {
            throw new WidgetNotFoundException("Widget not found with ID: {$id}");
        }

        $result = $widget->delete();
        $this->clearCache();
        
        return $result;
    }

    public function find(int $id): ?Widget
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn() => Widget::find($id)
        );
    }

    public function findByIdentifier(string $identifier): ?Widget
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'identifier:' . $identifier,
            self::CACHE_TTL,
            fn() => Widget::where('identifier', $identifier)->first()
        );
    }

    public function findByArea(string $area): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'area:' . $area,
            self::CACHE_TTL,
            fn() => Widget::where('area', $area)
                        ->orderBy('order')
                        ->get()
        );
    }

    public function getActive(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'active',
            self::CACHE_TTL,
            fn() => Widget::where('is_active', true)
                        ->orderBy('area')
                        ->orderBy('order')
                        ->get()
        );
    }

    public function updateOrder(array $order): void
    {
        foreach ($order as $id => $position) {
            Widget::where('id', $id)->update(['order' => $position]);
        }
        $this->clearCache();
    }

    public function updateVisibility(int $id, array $rules): void
    {
        $widget = $this->find($id);
        
        if (!$widget) {
            throw new WidgetNotFoundException("Widget not found with ID: {$id}");
        }

        $widget->visibility_rules = $rules;
        $widget->save();

        $this->clearCache();
    }

    private function mapDataToModel(Widget $widget, WidgetData $data): void
    {
        $widget->name = $data->name;
        $widget->identifier = $data->identifier;
        $widget->type = $data->type;
        $widget->area = $data->area;
        $widget->settings = $data->settings;
        $widget->order = $data->order;
        $widget->is_active = $data->isActive;
        $widget->cache_ttl = $data->cacheTtl;
        $widget->visibility_rules = $data->visibilityRules;
        $widget->permissions = $data->permissions;
        $widget->metadata = $data->metadata;
    }

    private function clearCache(): void
    {
        Cache::tags(['widgets'])->flush();
    }
}

// app/Core/Widget/Services/WidgetService.php
<?php

namespace App\Core\Widget\Services;

use App\Core\Widget\Contracts\WidgetRepositoryInterface;
use App\Core\Widget\DTO\WidgetData;
use App\Core\Widget\Models\Widget;
use App\Core\Widget\Events\WidgetOrderUpdated;
use App\Core\Widget\Events\WidgetVisibilityUpdated;
use App\Core\Widget\Exceptions\WidgetValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WidgetService
{
    public function __construct(
        private WidgetRepositoryInterface $repository
    ) {}

    public function createWidget(array $data): Widget
    {
        try {
            $widgetData = new WidgetData($data);
            $errors = $widgetData->validate();

            if (!empty($errors)) {
                throw new WidgetValidationException($errors);
            }

            return $this->repository->create($widgetData);
        } catch (\Exception $e) {
            Log::error('Failed to create widget', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function updateWidget(int $id, array $data): Widget
    {
        try {
            $widgetData = new WidgetData($data);
            $errors = $widgetData->validate();

            if (!empty($errors)) {
                throw new WidgetValidationException($errors);
            }

            return $this->repository->update($id, $widgetData);
        } catch (\Exception $e) {
            Log::error('Failed to update widget', [
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function deleteWidget(int $id): bool
    {
        try {
            return $this->repository->delete($id);
        } catch (\Exception $e) {
            Log::error('Failed to delete widget', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getWidgetsByArea(string $area): Collection
    {
        try {
            return Cache::remember(
                "widgets:area:{$area}",
                3600,
                fn() => $this->repository->findByArea($area)
            );
        } catch (\Exception $e) {
            Log::error('Failed to get widgets by area', [
                'area' => $area,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function updateWidgetOrder(array $order): void
    {
        try {
            $this->repository->updateOrder($order);
            Event::dispatch(new WidgetOrderUpdated($order));
        } catch (\Exception $e) {
            Log::error('Failed to update widget order', [
                'order' => $order,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function updateWidgetVisibility(int $id, array $rules): void
    {
        try {
            $this->repository->updateVisibility($id, $rules);
            Event::dispatch(new WidgetVisibilityUpdated($id, $rules));
        } catch (\Exception $e) {
            Log::error('Failed to update widget visibility', [
                'id' => $id,
                'rules' => $rules,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

// app/Core/Widget/Events/WidgetCreated.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Widget $widget)
    {
    }
}

// app/Core/Widget/Events/WidgetUpdated.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Widget $widget)
    {
    }
}

// app/Core/Widget/Events/WidgetDeleted.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Widget $widget)
    {
    }
}

// app/Core/Widget/Exceptions/WidgetNotFoundException.php
<?php

namespace App\Core\Widget\Exceptions;

class WidgetNotFoundException extends \Exception
{
}

// app/Core/Widget/Exceptions/WidgetValidationException.php
<?php

namespace App\Core\Widget\Exceptions;

class WidgetValidationException extends \Exception
{
    public function __construct(private array $errors)
    {
        parent::__construct('Widget validation failed');
    }

    public function getValidationErrors(): array
    {
        return $this->errors;
    }
}
