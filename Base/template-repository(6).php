<?php

namespace App\Core\Repositories;

use App\Core\Models\Template;
use App\Core\Exceptions\TemplateNotFoundException;
use App\Core\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TemplateRepository implements TemplateRepositoryInterface
{
    public function __construct(
        private Template $model
    ) {}

    public function findById(int $id): ?Template
    {
        try {
            return $this->model->with('sections')->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw new TemplateNotFoundException("Template with ID {$id} not found");
        }
    }

    public function findBySlug(string $slug): ?Template
    {
        try {
            return $this->model->with('sections')->where('slug', $slug)->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new TemplateNotFoundException("Template with slug {$slug} not found");
        }
    }

    public function getActive(): Collection
    {
        return $this->model->where('is_active', true)
            ->with('sections')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByTheme(string $theme): Collection
    {
        return $this->model->where('theme', $theme)
            ->with('sections')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function store(array $data): Template
    {
        if (!empty($data['is_default']) && $data['is_default']) {
            $this->clearDefaultTemplates();
        }
        
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Template
    {
        $template = $this->findById($id);

        if (!empty($data['is_default']) && $data['is_default']) {
            $this->clearDefaultTemplates($id);
        }

        $template->update($data);
        return $template->fresh(['sections']);
    }

    public function delete(int $id): bool
    {
        return (bool) $this->findById($id)->delete();
    }

    public function setDefault(int $id): bool
    {
        $this->clearDefaultTemplates($id);
        return (bool) $this->update($id, ['is_default' => true]);
    }

    private function clearDefaultTemplates(?int $excludeId = null): void
    {
        $query = $this->model->where('is_default', true);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_default' => false]);
    }
}
