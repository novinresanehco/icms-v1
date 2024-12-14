<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MenuRepository implements MenuRepositoryInterface
{
    protected $model;

    public function __construct(Menu $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->findOrFail($id);
    }

    public function findByLocation(string $location)
    {
        return $this->model
            ->where('location', $location)
            ->where('is_active', true)
            ->first();
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model
            ->when(isset($filters['location']), function ($query) use ($filters) {
                return $query->where('location', $filters['location']);
            })
            ->when(isset($filters['active']), function ($query) use ($filters) {
                return $query->where('is_active', $filters['active']);
            })
            ->orderBy('name')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            // If this menu is being set as active for a location, deactivate others
            if (isset($data['is_active']) && $data['is_active'] && isset($data['location'])) {
                $this->deactivateLocation($data['location']);
            }

            return $this->model->create($data);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $menu = $this->find($id);

            // If this menu is being set as active for a location, deactivate others
            if (isset($data['is_active']) && $data['is_active'] && isset($data['location'])) {
                $this->deactivateLocation($data['location'], $id);
            }

            $menu->update($data);
            return $menu->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            return $this->find($id)->delete();
        });
    }

    public function updateItems(int $id, array $items)
    {
        return DB::transaction(function () use ($id, $items) {
            $menu = $this->find($id);
            $menu->update(['items' => $this->validateAndFormatItems($items)]);
            return $menu->fresh();
        });
    }

    public function getLocations(): array
    {
        return config('cms.menu_locations', [
            'primary' => 'Primary Navigation',
            'footer' => 'Footer Navigation',
            'sidebar' => 'Sidebar Navigation'
        ]);
    }

    public function duplicate(int $id)
    {
        return DB::transaction(function () use ($id) {
            $menu = $this->find($id);
            
            $newMenu = $menu->replicate();
            $newMenu->name = $menu->name . ' (Copy)';
            $newMenu->is_active = false;
            $newMenu->save();
            
            return $newMenu;
        });
    }

    protected function deactivateLocation(string $location, ?int $exceptId = null)
    {
        $query = $this->model->where('location', $location);
        
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        
        $query->update(['is_active' => false]);
    }

    protected function validateAndFormatItems(array $items): array
    {
        return array_map(function ($item) {
            $formatted = [
                'title' => $item['title'],
                'type' => $item['type'],
                'target' => $item['target'] ?? '_self'
            ];

            switch ($item['type']) {
                case 'content':
                    $formatted['content_id'] = (int) $item['content_id'];
                    break;
                case 'category':
                    $formatted['category_id'] = (int) $item['category_id'];
                    break;
                case 'custom':
                    $formatted['url'] = $item['url'];
                    break;
            }

            if (isset($item['children']) && is_array($item['children'])) {
                $formatted['children'] = $this->validateAndFormatItems($item['children']);
            }

            return $formatted;
        }, $items);
    }
}
