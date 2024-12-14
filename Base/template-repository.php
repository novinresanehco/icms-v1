<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Template;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Interfaces\TemplateRepositoryInterface;

class TemplateRepository implements TemplateRepositoryInterface
{
    private const CACHE_PREFIX = 'template:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Template $model
    ) {}

    public function findById(int $id): ?Template
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->find($id)
        );
    }

    public function findBySlug(string $slug): ?Template
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->where('slug', $slug)->first()
        );
    }

    public function create(array $data): Template
    {
        $template = $this->model->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'content' => $data['content'],
            'type' => $data['type'] ?? 'page',
            'is_active' => $data['is_active'] ?? true,
            'settings' => $data['settings'] ?? [],
            'author_id' => auth()->id()
        ]);

        $this->clearCache();

        return $template;
    }

    public function update(int $id, array $data): bool
    {
        $template = $this->findById($id);
        
        if (!$template) {
            return false;
        }

        $updated = $template->update([
            'name' => $data['name'] ?? $template->name,
            'slug' => $data['slug'] ?? $template->slug,
            'description' => $data['description'] ?? $template->description,
            'content' => $data['content'] ?? $template->content,
            'type' => $data['type'] ?? $template->type,
            'is_active' => $data['is_active'] ?? $template->is_active,
            'settings' => $data['settings'] ?? $template->settings
        ]);

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $template = $this->findById($id);
        
        if (!$template || $template->is_system) {
            return false;
        }

        $deleted = $template->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function getAll(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            fn () => $this->model->orderBy('name')->get()
        );
    }

    public function getByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('type', $type)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function findDefault(string $type = 'page'): ?Template
    {
        return Cache::remember(
            self::CACHE_PREFIX . "default:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('type', $type)
                ->where('is_active', true)
                ->where('is_default', true)
                ->first()
        );
    }

    public function setDefault(int $id, string $type): bool
    {
        $template = $this->findById($id);
        
        if (!$template || !$template->is_active) {
            return false;
        }

        // Remove current default
        $this->model->where('type', $type)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Set new default
        $updated = $template->update(['is_default' => true]);

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }

    protected function clearCache(): void
    {
        $keys = ['all'];
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}