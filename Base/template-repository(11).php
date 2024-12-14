<?php

namespace App\Repositories;

use App\Models\Template;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TemplateRepository implements TemplateRepositoryInterface
{
    protected $model;

    public function __construct(Template $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->findOrFail($id);
    }

    public function findBySlug(string $slug)
    {
        return $this->model->where('slug', $slug)->firstOrFail();
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model
            ->when(isset($filters['search']), function ($query) use ($filters) {
                return $query->where(function ($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('description', 'like', "%{$filters['search']}%");
                });
            })
            ->when(isset($filters['type']), function ($query) use ($filters) {
                return $query->where('type', $filters['type']);
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
            if (!isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }
            return $this->model->create($data);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $template = $this->find($id);
            
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }
            
            $template->update($data);
            return $template->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $template = $this->find($id);
            
            // Don't allow deletion of default template
            if ($template->is_default) {
                throw new \Exception('Cannot delete the default template.');
            }
            
            return $template->delete();
        });
    }

    public function duplicate(int $id)
    {
        return DB::transaction(function () use ($id) {
            $template = $this->find($id);
            
            $newTemplate = $template->replicate();
            $newTemplate->name = $template->name . ' (Copy)';
            $newTemplate->slug = Str::slug($newTemplate->name);
            $newTemplate->is_default = false;
            $newTemplate->save();
            
            return $newTemplate;
        });
    }

    public function getDefault()
    {
        return $this->model->where('is_default', true)->first();
    }

    public function setDefault(int $id)
    {
        return DB::transaction(function () use ($id) {
            // Remove default flag from all templates
            $this->model->where('is_default', true)
                ->update(['is_default' => false]);
            
            // Set new default template
            $template = $this->find($id);
            $template->update(['is_default' => true]);
            
            return $template;
        });
    }

    public function getAvailable(): Collection
    {
        return $this->model
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
