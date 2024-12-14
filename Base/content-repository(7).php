<?php

namespace App\Repositories;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContentRepository implements ContentRepositoryInterface 
{
    protected Content $model;
    protected array $searchable = ['title', 'content', 'excerpt'];
    protected int $cacheTTL = 3600; // 1 hour

    public function __construct(Content $model) 
    {
        $this->model = $model;
    }

    public function create(array $data): ?int 
    {
        try {
            DB::beginTransaction();

            $content = $this->model->create([
                'title' => $data['title'],
                'slug' => $data['slug'] ?? str($data['title'])->slug(),
                'content' => $data['content'],
                'excerpt' => $data['excerpt'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'type' => $data['type'] ?? 'post',
                'author_id' => auth()->id(),
                'parent_id' => $data['parent_id'] ?? null,
                'order' => $data['order'] ?? 0,
                'template' => $data['template'] ?? 'default',
                'metadata' => $data['metadata'] ?? [],
                'published_at' => $data['published_at'] ?? null,
            ]);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->clearContentCache();
            DB::commit();

            return $content->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create content: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool 
    {
        try {
            DB::beginTransaction();

            $content = $this->model->findOrFail($id);
            
            $updateData = [
                'title' => $data['title'] ?? $content->title,
                'slug' => $data['slug'] ?? ($data['title'] ? str($data['title'])->slug() : $content->slug),
                'content' => $data['content'] ?? $content->content,
                'excerpt' => $data['excerpt'] ?? $content->excerpt,
                'status' => $data['status'] ?? $content->status,
                'type' => $data['type'] ?? $content->type,
                'parent_id' => $data['parent_id'] ?? $content->parent_id,
                'order' => $data['order'] ?? $content->order,
                'template' => $data['template'] ?? $content->template,
                'metadata' => array_merge($content->metadata ?? [], $data['metadata'] ?? []),
                'published_at' => $data['published_at'] ?? $content->published_at,
            ];

            $content->update($updateData);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->clearContentCache($id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update content: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool 
    {
        try {
            DB::beginTransaction();

            $content = $this->model->findOrFail($id);
            
            // Delete relationships
            $content->categories()->detach();
            $content->tags()->detach();
            $content->media()->detach();
            
            // Delete content
            $content->delete();

            $this->clearContentCache($id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete content: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $id): ?array 
    {
        try {
            return Cache::remember(
                "content.{$id}",
                $this->cacheTTL,
                fn() => $this->model->with(['categories', 'tags', 'author', 'media'])
                    ->findOrFail($id)
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get content: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator 
    {
        try {
            $query = $this->model->query()->with(['categories', 'tags', 'author']);

            // Apply filters
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (!empty($filters['author_id'])) {
                $query->where('author_id', $filters['author_id']);
            }

            if (!empty($filters['category_id'])) {
                $query->whereHas('categories', function ($q) use ($filters) {
                    $q->where('categories.id', $filters['category_id']);
                });
            }

            if (!empty($filters['tag_id'])) {
                $query->whereHas('tags', function ($q) use ($filters) {
                    $q->where('tags.id', $filters['tag_id']);
                });
            }

            if (!empty($filters['search'])) {
                $query->where(function ($q) use ($filters) {
                    foreach ($this->searchable as $field) {
                        $q->orWhere($field, 'LIKE', "%{$filters['search']}%");
                    }
                });
            }

            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            // Apply ordering
            $orderBy = $filters['order_by'] ?? 'created_at';
            $orderDir = $filters['order_dir'] ?? 'desc';
            $query->orderBy($orderBy, $orderDir);

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated content: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getByType(string $type): Collection 
    {
        try {
            return Cache::remember(
                "content.type.{$type}",
                $this->cacheTTL,
                fn() => $this->model->where('type', $type)
                    ->with(['categories', 'tags', 'author'])
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get content by type: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getBySlug(string $slug): ?array 
    {
        try {
            return Cache::remember(
                "content.slug.{$slug}",
                $this->cacheTTL,
                fn() => $this->model->with(['categories', 'tags', 'author', 'media'])
                    ->where('slug', $slug)
                    ->firstOrFail()
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get content by slug: ' . $e->getMessage());
            return null;
        }
    }

    protected function clearContentCache(int $contentId = null): void 
    {
        if ($contentId) {
            Cache::forget("content.{$contentId}");
            
            // Get content to clear slug cache
            $content = $this->model->find($contentId);
            if ($content) {
                Cache::forget("content.slug.{$content->slug}");
            }
        }

        // Clear type caches
        $types = $this->model->distinct()->pluck('type');
        foreach ($types as $type) {
            Cache::forget("content.type.{$type}");
        }
    }
}
