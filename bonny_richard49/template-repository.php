<?php

namespace App\Core\Template\Repository;

use App\Core\Template\Models\Template;
use App\Core\Template\DTO\TemplateData;
use App\Core\Template\Events\TemplateCreated;
use App\Core\Template\Events\TemplateUpdated;
use App\Core\Template\Events\TemplateDeleted;
use App\Core\Template\Events\TemplateDefaultChanged;
use App\Core\Template\Services\TemplateValidator;
use App\Core\Template\Services\TemplateProcessor;
use App\Core\Template\Exceptions\TemplateNotFoundException;
use App\Core\Template\Exceptions\TemplateSyntaxException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

class TemplateRepository extends BaseRepository implements TemplateRepositoryInterface
{
    protected const CACHE_KEY = 'templates';
    protected const CACHE_TTL = 3600; // 1 hour

    protected TemplateValidator $validator;
    protected TemplateProcessor $processor;

    public function __construct(
        CacheManagerInterface $cache,
        TemplateValidator $validator,
        TemplateProcessor $processor
    ) {
        parent::__construct($cache);
        $this->validator = $validator;
        $this->processor = $processor;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Template::class;
    }

    public function findBySlug(string $slug): ?Template
    {
        return $this->cache->remember(
            $this->getCacheKey("slug:{$slug}"),
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    public function getByType(string $type): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("type:{$type}"),
            fn() => $this->model->where('type', $type)
                               ->orderBy('name')
                               ->get()
        );
    }

    public function getActive(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('active'),
            fn() => $this->model->where('is_active', true)
                               ->orderBy('type')
                               ->orderBy('name')
                               ->get()
        );
    }

    public function getVariables(int $id): array
    {
        $template = $this->findOrFail($id);
        return $this->processor->extractVariables($template->content);
    }

    public function setAsDefault(int $id, string $type): Template
    {
        DB::beginTransaction();
        try {
            // Remove current default
            $this->model->where('type', $type)
                       ->where('is_default', true)
                       ->update(['is_default' => false]);

            // Set new default
            $template = $this->findOrFail($id);
            $template->update([
                'is_default' => true,
                'type' => $type
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new TemplateDefaultChanged($template, $type));

            DB::commit();
            return $template->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getDefaultForType(string $type): ?Template
    {
        return $this->cache->remember(
            $this->getCacheKey("default:{$type}"),
            fn() => $this->model->where('type', $type)
                               ->where('is_default', true)
                               ->first()
        );
    }

    public function duplicate(int $id, array $overrides = []): Template
    {
        DB::beginTransaction();
        try {
            $original = $this->findOrFail($id);
            
            $data = array_merge([
                'name' => $original->name . ' (Copy)',
                'slug' => $original->slug . '-copy',
                'content' => $original->content,
                'type' => $original->type,
                'description' => $original->description,
                'is_active' => false,
                'is_default' => false,
                'parent_id' => $original->parent_id,
                'settings' => $original->settings,
            ], $overrides);

            $template = $this->model->create($data);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new TemplateCreated($template));

            DB::commit();
            return $template;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateSyntax(string $content): array
    {
        return $this->validator->validate($content);
    }

    public function getUsageStats(int $id): array
    {
        return $this->cache->remember(
            $this->getCacheKey("stats:{$id}"),
            function() use ($id) {
                $template = $this->findOrFail($id);
                
                return [
                    'total_usage' => $template->usages()->count(),
                    'by_type' => $template->usages()
                        ->select('model_type', DB::raw('count(*) as count'))
                        ->groupBy('model_type')
                        ->pluck('count', 'model_type')
                        ->toArray(),
                    'last_used' => $template->last_used_at,
                    'child_templates' => $template->children()->count(),
                ];
            }
        );
    }

    public function importFromFile(string $path, array $data = []): Template
    {
        if (!File::exists($path)) {
            throw new \InvalidArgumentException("Template file not found: {$path}");
        }

        $content = File::get($path);
        
        // Validate template syntax
        $errors = $this->validateSyntax($content);
        if (!empty($errors)) {
            throw new TemplateSyntaxException("Invalid template syntax: " . implode(', ', $errors));
        }

        return $this->create(array_merge([
            'content' => $content,
            'name' => basename($path, '.blade.php'),
        ], $data));
    }

    public function exportToFile(int $id, string $path): bool
    {
        $template = $this->findOrFail($id);
        
        try {
            File::put($path, $template->content);
            return true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to export template: {$e->getMessage()}");
        }
    }

    public function getInheritanceChain(int $id): array
    {
        return $this->cache->remember(
            $this->getCacheKey("inheritance:{$id}"),
            function() use ($id) {
                $template = $this->findOrFail($id);
                $chain = [];
                
                while ($template->parent) {
                    $chain[] = [
                        'id' => $template->parent->id,
                        'name' => $template->parent->name,
                        'level' => count($chain) + 1
                    ];
                    $template = $template->parent;
                }
                
                return array_reverse($chain);
            }
        );
    }
}
