<?php

namespace App\Core\Repositories;

use App\Core\Models\Template;
use App\Core\Repositories\Contracts\TemplateRepositoryInterface;
use App\Core\Exceptions\TemplateException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemplateRepository implements TemplateRepositoryInterface
{
    /**
     * @var Template
     */
    protected $model;

    /**
     * @var int Cache duration in minutes
     */
    protected const CACHE_DURATION = 60;

    /**
     * Constructor
     */
    public function __construct(Template $model)
    {
        $this->model = $model;
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Template
    {
        return Cache::remember(
            "templates.{$id}",
            self::CACHE_DURATION,
            fn() => $this->model->with(['regions', 'author'])->find($id)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function all(array $filters = []): Collection
    {
        $query = $this->model->with(['regions', 'author']);
        
        return $this->applyFilters($query, $filters)->get();
    }

    /**
     * {@inheritdoc}
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['regions', 'author']);
        
        return $this->applyFilters($query, $filters)->paginate($perPage);
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data): Template
    {
        try {
            DB::beginTransaction();
            
            $template = $this->model->create($data);
            
            if (isset($data['regions'])) {
                $template->regions()->createMany($data['regions']);
            }
            
            DB::commit();
            
            // Clear relevant caches
            $this->clearCache();
            
            return $template->fresh(['regions', 'author']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create template:', ['error' => $e->getMessage()]);
            throw new TemplateException('Failed to create template: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(Template $template, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $template->update($data);
            
            if (isset($data['regions'])) {
                // Remove existing regions
                $template->regions()->delete();
                // Create new regions
                $template->regions()->createMany($data['regions']);
            }
            
            DB::commit();
            
            // Clear relevant caches
            $this->clearCache($template->id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update template:', [
                'id' => $template->id,
                'error' => $e->getMessage()
            ]);
            throw new TemplateException('Failed to update template: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(Template $template): bool
    {
        try {
            DB::beginTransaction();
            
            // Delete related regions first
            $template->regions()->delete();
            $template->delete();
            
            DB::commit();
            
            // Clear relevant caches
            $this->clearCache($template->id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete template:', [
                'id' => $template->id,
                'error' => $e->getMessage()
            ]);
            throw new TemplateException('Failed to delete template: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findBySlug(string $slug): ?Template
    {
        return Cache::remember(
            "templates.slug.{$slug}",
            self::CACHE_DURATION,
            fn() => $this->model->where('slug', $slug)->with(['regions', 'author'])->first()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getByType(string $type, array $filters = []): Collection
    {
        $query = $this->model->byType($type)->with(['regions', 'author']);
        
        return $this->applyFilters($query, $filters)->get();
    }

    /**
     * {@inheritdoc}
     */
    public function getByCategory(string $category, array $filters = []): Collection
    {
        $query = $this->model->byCategory($category)->with(['regions', 'author']);
        
        return $this->applyFilters($query, $filters)->get();
    }

    /**
     * {@inheritdoc}
     */
    public function getActive(array $filters = []): Collection
    {
        $query = $this->model->active()->with(['regions', 'author']);
        
        return $this->applyFilters($query, $filters)->get();
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): object
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        if (!empty($filters['sort'])) {
            $direction = $filters['direction'] ?? 'asc';
            $query->orderBy($filters['sort'], $direction);
        }

        return $query;
    }

    /**
     * Clear template caches
     */
    protected function clearCache(?int $templateId = null): void
    {
        if ($templateId) {
            Cache::forget("templates.{$templateId}");
            // Also clear any cached slug
            $template = $this->model->find($templateId);
            if ($template) {
                Cache::forget("templates.slug.{$template->slug}");
            }
        }
        
        // Clear any collection caches
        Cache::tags(['templates'])->flush();
    }
}
