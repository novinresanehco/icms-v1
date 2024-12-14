<?php

namespace App\Repositories;

use App\Models\WidgetArea;
use App\Repositories\Contracts\WidgetAreaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WidgetAreaRepository implements WidgetAreaRepositoryInterface
{
    protected $model;

    public function __construct(WidgetArea $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->with('widgets')->findOrFail($id);
    }

    public function findBySlug(string $slug)
    {
        return $this->model
            ->with('widgets')
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model
            ->with('widgets')
            ->when(isset($filters['active']), function ($query) use ($filters) {
                return $query->where('is_active', $filters['active']);
            })
            ->orderBy('name')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            if (!isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }
            
            return $this->model->create($data);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $area = $this->find($id);
            
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }
            
            $area->update($data);
            return $area->fresh('widgets');
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $area = $this->find($id);
            
            // Delete all widgets in this area
            $area->widgets()->delete();
            
            return $area->delete();
        });
    }

    public function getActive(): Collection
    {
        return $this->model
            ->with(['widgets' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('order');
            }])
            ->where('is_active', true)
            ->get();
    }

    public function activate(int $id)
    {
        return DB::transaction(function () use ($id) {
            $area = $this->find($id);
            $area->update(['is_active' => true]);
            return $area->fresh('widgets');
        });
    }

    public function deactivate(int $id)
    {
        return DB::transaction(function () use ($id) {
            $area = $this->find($id);
            $area->update(['is_active' => false]);
            return $area->fresh('widgets');
        });
    }
}
