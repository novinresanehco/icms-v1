<?php

namespace App\Core\Repositories;

use App\Core\Models\Content;
use App\Core\Repositories\Contracts\ContentRepositoryInterface;
use App\Core\Exceptions\ContentException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache, DB, Log};

class ContentRepository implements ContentRepositoryInterface
{
    protected Content $model;
    protected const CACHE_TTL = 3600;

    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Content
    {
        return Cache::remember("content.{$id}", self::CACHE_TTL, function () use ($id) {
            return $this->model->with(['author', 'categories', 'tags', 'meta'])
                             ->find($id);
        });
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::remember("content.slug.{$slug}", self::CACHE_TTL, function () use ($slug) {
            return $this->model->with(['author', 'categories', 'tags', 'meta'])
                             ->where('slug', $slug)
                             ->first();
        });
    }

    public function all(array $filters = []): Collection
    {
        $query = $this->model->with(['author', 'categories', 'tags']);
        return $this->applyFilters($query, $filters)->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['author', 'categories', 'tags']);
        return $this->applyFilters($query, $filters)->paginate($perPage);
    }

    public function create(array $data): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->model->create($data);

            if (isset($data['categories'])) {
                $content->categories()->attach($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->attach($data['tags']);
            }

            if (isset($data['meta'])) {
                $content->meta()->createMany($data['meta']);
            }

            DB::commit();
            $this->clearCache();

            return $content->fresh(['author', 'categories', 'tags', 'meta']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content creation failed:', ['error' => $e->getMessage(), 'data' => $data]);
            throw new ContentException('Failed to create content: ' . $e->getMessage());
        }
    }

    public function update(Content $content, array $data): bool
    {
        try {
            DB::beginTransaction();

            $content->update($data);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            if (isset($data['meta'])) {
                $content->meta()->delete();
                $content->meta()->createMany($data['meta']);
            }

            DB::commit();
            $this->clearCache($content->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content update failed:', ['id' => $content->id, 'error' => $e->getMessage()]);
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function delete(Content $content): bool
    {
        try {
            DB::beginTransaction();
            
            $content->meta()->delete();
            $content->categories()->detach();
            $content->tags()->detach();
            $content->delete();

            DB::commit();
            $this->clearCache($content->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content deletion failed:', ['id' => $content->id, 'error' => $e->getMessage()]);
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    public function getByType(string $type): Collection
    {
        return $this->model->where('type', $type)
                          ->with(['author', 'categories', 'tags'])
                          ->get();
    }

    public function getByStatus(string $status): Collection
    {
        return $this->model->where('status', $status)
                          ->with(['author', 'categories', 'tags'])
                          ->get();
    }

    public function getPublished(): Collection
    {
        return $this->model->published()
                          ->with(['author', 'categories', 'tags'])
                          ->get();
    }

    public function getByAuthor(int $authorId): Collection
    {
        return $this->model->where('author_id', $authorId)
                          ->with(['categories', 'tags'])
                          ->get();
    }

    public function getVersions(int $contentId): Collection
    {
        return DB::table('content_versions')
                 ->where('content_id', $contentId)
                 ->orderBy('created_at', 'desc')
                 ->get();
    }

    protected function applyFilters($query, array $filters): object
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'like', "%{$filters['search']}%")
                  ->orWhere('content', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
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

        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        return $query;
    }

    protected function clearCache(?int $contentId = null): void
    {
        if ($contentId) {
            Cache::forget("content.{$contentId}");
            $content = $this->model->find($contentId);
            if ($content) {
                Cache::forget("content.slug.{$content->slug}");
            }
        }
        
        Cache::tags(['content'])->flush();
    }
}
