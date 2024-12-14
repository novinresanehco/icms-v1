<?php

namespace App\Repositories;

use App\Models\Widget;
use App\Repositories\Contracts\WidgetRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WidgetRepository implements WidgetRepositoryInterface
{
    protected $model;

    public function __construct(Widget $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->findOrFail($id);
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model
            ->when(isset($filters['area']), function ($query) use ($filters) {
                return $query->where('area', $filters['area']);
            })
            ->when(isset($filters['type']), function ($query) use ($filters) {
                return $query->where('type', $filters['type']);
            })
            ->when(isset($filters['active']), function ($query) use ($filters) {
                return $query->where('is_active', $filters['active']);
            })
            ->orderBy('order')
            ->orderBy('created_at')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            // Set order to last position if not specified
            if (!isset($data['order'])) {
                $data['order'] = $this->getLastOrder($data['area']) + 1;
            }

            // Validate and format widget settings
            if (isset($data['settings'])) {
                $data['settings'] = $this->validateSettings($data['type'], $data['settings']);
            }

            return $this->model->create($data);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $widget = $this->find($id);

            // Validate and format widget settings if provided
            if (isset($data['settings'])) {
                $data['settings'] = $this->validateSettings($data['type'] ?? $widget->type, $data['settings']);
            }

            $widget->update($data);
            return $widget->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $widget = $this->find($id);
            $this->reorderAfterDelete($widget->area, $widget->order);
            return $widget->delete();
        });
    }

    public function getByArea(string $area): Collection
    {
        return $this->model
            ->where('area', $area)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();
    }

    public function updateOrder(string $area, array $order)
    {
        return DB::transaction(function () use ($area, $order) {
            foreach ($order as $position => $widgetId) {
                $this->model->where('id', $widgetId)
                    ->where('area', $area)
                    ->update(['order' => $position + 1]);
            }
            
            return $this->getByArea($area);
        });
    }

    public function getAvailableTypes(): array
    {
        return [
            'text' => [
                'name' => 'Text Widget',
                'description' => 'Display custom text or HTML content',
                'settings' => [
                    'content' => ['type' => 'textarea', 'required' => true],
                    'format' => ['type' => 'select', 'options' => ['text', 'html']],
                ]
            ],
            'recent_posts' => [
                'name' => 'Recent Posts',
                'description' => 'Display a list of recent posts',
                'settings' => [
                    'limit' => ['type' => 'number', 'min' => 1, 'max' => 20],
                    'category_id' => ['type' => 'category'],
                    'show_date' => ['type' => 'boolean'],
                    'show_excerpt' => ['type' => 'boolean'],
                ]
            ],
            'categories' => [
                'name' => 'Categories',
                'description' => 'Display a list of categories',
                'settings' => [
                    'show_count' => ['type' => 'boolean'],
                    'show_hierarchy' => ['type' => 'boolean'],
                    'exclude' => ['type' => 'category_multi'],
                ]
            ],
            'tag_cloud' => [
                'name' => 'Tag Cloud',
                'description' => 'Display a cloud of popular tags',
                'settings' => [
                    'limit' => ['type' => 'number', 'min' => 1, 'max' => 50],
                    'min_size' => ['type' => 'number', 'min' => 8, 'max' => 20],
                    'max_size' => ['type' => 'number', 'min' => 12, 'max' => 40],
                ]
            ]
        ];
    }

    public function duplicate(int $id)
    {
        return DB::transaction(function () use ($id) {
            $widget = $this->find($id);
            
            $newWidget = $widget->replicate();
            $newWidget->name = $widget->name . ' (Copy)';
            $newWidget->order = $this->getLastOrder($widget->area) + 1;
            $newWidget->is_active = false;
            $newWidget->save();
            
            return $newWidget;
        });
    }

    public function bulkUpdate(array $widgets)
    {
        return DB::transaction(function () use ($widgets) {
            foreach ($widgets as $widgetData) {
                if (isset($widgetData['id'])) {
                    $this->update($widgetData['id'], $widgetData);
                }
            }
            
            return true;
        });
    }

    protected function getLastOrder(string $area): int
    {
        return $this->model
            ->where('area', $area)
            ->max('order') ?? 0;
    }

    protected function reorderAfterDelete(string $area, int $deletedOrder)
    {
        $this->model
            ->where('area', $area)
            ->where('order', '>', $deletedOrder)
            ->decrement('order');
    }

    protected function validateSettings(string $type, array $settings): array
    {
        $availableTypes = $this->getAvailableTypes();
        
        if (!isset($availableTypes[$type])) {
            throw new \InvalidArgumentException("Invalid widget type: {$type}");
        }

        $validSettings = $availableTypes[$type]['settings'];
        $validated = [];

        foreach ($validSettings as $key => $rules) {
            if (isset($rules['required']) && $rules['required'] && !isset($settings[$key])) {
                throw new \InvalidArgumentException("Missing required setting: {$key}");
            }

            if (isset($settings[$key])) {
                $value = $settings[$key];

                switch ($rules['type']) {
                    case 'number':
                        $value = (int) $value;
                        if (isset($rules['min']) && $value < $rules['min']) {
                            $value = $rules['min'];
                        }
                        if (isset($rules['max']) && $value > $rules['max']) {
                            $value = $rules['max'];
                        }
                        break;

                    case 'boolean':
                        $value = (bool) $value;
                        break;

                    case 'select':
                        if (!in_array($value, $rules['options'])) {
                            throw new \InvalidArgumentException("Invalid option for setting {$key}: {$value}");
                        }
                        break;
                }

                $validated[$key] = $value;
            }
        }

        return $validated;
    }
}
