<?php

namespace App\Core\Template\Repositories;

use App\Core\Template\Models\Template;
use App\Core\Template\Contracts\TemplateRepositoryInterface;
use App\Core\Template\Exceptions\TemplateNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TemplateRepository implements TemplateRepositoryInterface
{
    protected Template $model;

    public function __construct(Template $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Template
    {
        $template = $this->model->create($data);
        $this->clearCache();
        return $template;
    }

    public function update(int $id, array $data): Template
    {
        $template = $this->find($id);
        
        if (!$template) {
            throw new TemplateNotFoundException("Template with ID {$id} not found");
        }

        $template->update($data);
        $this->clearCache();
        return $template;
    }

    public function delete(int $id): bool
    {
        $template = $this->find($id);
        
        if (!$template) {
            throw new TemplateNotFoundException("Template with ID {$id} not found");
        }

        $result = $template->delete();
        $this->clearCache();
        return $result;
    }

    public function find(int $id): ?Template
    {
        return Cache::tags(['templates'])
            ->remember("template.{$id}", 3600, function () use ($id) {
                return $this->model->with(['regions', 'variables'])->find($id);
            });
    }

    public function findBySlug(string $slug): ?Template
    {
        return Cache::tags(['templates'])
            ->remember("template.slug.{$slug}", 3600, function () use ($slug) {
                return $this->model->with(['regions', 'variables'])
                    ->where('slug', $slug)
                    ->first();
            });
    }

    public function getActive(): Collection
    {
        return Cache::tags(['templates'])
            ->remember('templates.active', 3600, function () {
                return $this->model->with(['regions', 'variables'])
                    ->where('is_active', true)
                    ->get();
            });
    }

    protected function clearCache(): void
    {
        Cache::tags(['templates'])->flush();
    }
}
