<?php

namespace App\Core\Repositories;

use App\Models\Content;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentRepository extends AdvancedRepository 
{
    protected $model = Content::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createContent(array $data): Content
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'content' => $data['content'],
                'type' => $data['type'],
                'status' => $data['status'] ?? 'draft',
                'meta' => $data['meta'] ?? [],
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);
        });
    }

    public function updateContent(Content $content, array $data): void
    {
        $this->executeTransaction(function() use ($content, $data) {
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'slug' => $data['slug'] ?? $content->slug,
                'content' => $data['content'] ?? $content->content,
                'type' => $data['type'] ?? $content->type,
                'status' => $data['status'] ?? $content->status,
                'meta' => array_merge($content->meta ?? [], $data['meta'] ?? []),
                'updated_at' => now()
            ]);

            $this->cache->forget("content:{$content->id}");
        });
    }

    public function publish(Content $content): void
    {
        $this->executeTransaction(function() use ($content) {
            $content->update([
                'status' => 'published',
                'published_at' => now()
            ]);
            
            $this->cache->forget("content:{$content->id}");
        });
    }

    public function unpublish(Content $content): void
    {
        $this->executeTransaction(function() use ($content) {
            $content->update([
                'status' => 'draft',
                'published_at' => null
            ]);
            
            $this->cache->forget("content:{$content->id}");
        });
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->executeQuery(function() use ($slug) {
            return $this->model
                ->where('slug', $slug)
                ->where('status', 'published')
                ->first();
        });
    }

    public function getPublishedContent(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->executeQuery(function() use ($filters, $perPage) {
            $query = $this->model->where('status', 'published');
            
            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (!empty($filters['created_by'])) {
                $query->where('created_by', $filters['created_by']);
            }

            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            return $query->orderBy('created_at', 'desc')->paginate($perPage);
        });
    }
}

class TemplateRepository extends AdvancedRepository 
{
    protected $model = Template::class;
    protected $cache;

    public function __construct(CacheService $cache) 
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createTemplate(array $data): Template 
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'name' => $data['name'],
                'path' => $data['path'],
                'type' => $data['type'],
                'config' => $data['config'] ?? [],
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);
        });
    }

    public function updateTemplate(Template $template, array $data): void
    {
        $this->executeTransaction(function() use ($template, $data) {
            $template->update([
                'name' => $data['name'] ?? $template->name,
                'path' => $data['path'] ?? $template->path,
                'type' => $data['type'] ?? $template->type,
                'config' => array_merge($template->config ?? [], $data['config'] ?? []),
                'is_active' => $data['is_active'] ?? $template->is_active,
                'updated_at' => now()
            ]);
            
            $this->cache->forget("template:{$template->id}");
        });
    }

    public function getActiveTemplates(string $type = null): Collection
    {
        return $this->executeQuery(function() use ($type) {
            $query = $this->model->where('is_active', true);
            
            if ($type) {
                $query->where('type', $type);
            }
            
            return $query->orderBy('name')->get();
        });
    }
}

class ModuleRepository extends AdvancedRepository
{
    protected $model = Module::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function registerModule(array $data): Module
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'name' => $data['name'],
                'version' => $data['version'],
                'description' => $data['description'] ?? '',
                'dependencies' => $data['dependencies'] ?? [],
                'config' => $data['config'] ?? [],
                'is_enabled' => $data['is_enabled'] ?? false,
                'created_at' => now()
            ]);
        });
    }

    public function updateModuleConfig(Module $module, array $config): void 
    {
        $this->executeTransaction(function() use ($module, $config) {
            $module->update([
                'config' => array_merge($module->config ?? [], $config),
                'updated_at' => now()
            ]);
            
            $this->cache->forget("module:{$module->id}");
        });
    }

    public function enableModule(Module $module): void
    {
        $this->executeTransaction(function() use ($module) {
            $module->update([
                'is_enabled' => true,
                'enabled_at' => now()
            ]);
            
            $this->cache->forget("module:{$module->id}");
        });
    }

    public function disableModule(Module $module): void
    {
        $this->executeTransaction(function() use ($module) {
            $module->update([
                'is_enabled' => false,
                'enabled_at' => null
            ]);
            
            $this->cache->forget("module:{$module->id}");
        });
    }

    public function getEnabledModules(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->where('is_enabled', true)
                ->orderBy('name')
                ->get();
        });
    }
}
