<?php

namespace App\Core\Repositories;

use App\Core\Models\Module;
use App\Core\Exceptions\ModuleNotFoundException;
use App\Core\Repositories\Contracts\ModuleRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ModuleRepository implements ModuleRepositoryInterface
{
    public function __construct(
        private Module $model
    ) {}

    public function findById(int $id): ?Module
    {
        try {
            return $this->model->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw new ModuleNotFoundException("Module with ID {$id} not found");
        }
    }

    public function findBySlug(string $slug): ?Module
    {
        try {
            return $this->model->where('slug', $slug)->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ModuleNotFoundException("Module with slug {$slug} not found");
        }
    }

    public function getActive(): Collection
    {
        return $this->model->where('is_active', true)
            ->orderBy('priority')
            ->get();
    }

    public function store(array $data): Module
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Module
    {
        $module = $this->findById($id);
        $module->update($data);
        return $module->fresh();
    }

    public function delete(int $id): bool
    {
        return (bool) $this->findById($id)->delete();
    }

    public function activate(int $id): bool
    {
        return (bool) $this->update($id, ['is_active' => true]);
    }

    public function deactivate(int $id): bool
    {
        return (bool) $this->update($id, ['is_active' => false]);
    }
}
