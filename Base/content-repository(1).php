<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Content;
use App\Models\ContentMeta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\ContentRepositoryInterface;

class ContentRepository implements ContentRepositoryInterface
{
    private const CACHE_PREFIX = 'content:';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly Content $model,
        private readonly ContentMeta $metaModel
    ) {}

    public function findById(int $id, array $with = []): ?Content
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with($with)->find($id)
        );
    }

    public function findBySlug(string $slug, array $with = []): ?Content
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->with($with)->where('slug', $slug)->first()
        );
    }

    public function create(array $data): Content
    {
        return DB::transaction(function () use ($data) {
            $content = $this->model->create([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'content' => $data['content'],
                'status' => $data['status'] ?? 'draft',
                'type' => $data['type'] ?? 'page',
                'user_id' => $data['user_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'template' => $data['template'] ?? 'default',
                'order' => $data['order'] ?? 0,
            ]);

            if (isset($data['meta'])) {
                $this->updateMeta($content->id, $data['meta']);
            }

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->clearCache($content->id);

            return $content;
        });
    }

    public function update(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            $content = $this->findById($id);
            
            if (!$content) {
                return false;
            }

            $updated = $content->update([
                'title' => $data['title'] ?? $content->title,
                'slug' => $data['slug'] ?? $content->slug,
                'content' => $data['content'] ?? $content->content,
                'status' => $data['status'] ?? $content->status,
                'type' => $data['type'] ?? $content->type,
                'parent_id' => $data['parent_id'] ?? $content->parent_id,
                'template' => $data['template'] ?? $content->template,
                'order' => $data['order'] ?? $content->order,
            ]);

            if (isset($data['meta'])) {
                $this->updateMeta($id, $data['meta']);
            }

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->clearCache($id);

            return $updated;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $content = $this->findById($id);
            
            if (!$content) {
                return false;
            }

            $content->categories()->detach();
            $content->tags()->detach();
            $this->metaModel->where('content_id', $id)->delete();
            
            $deleted = $content->delete();
            
            if ($deleted) {
                $this->clearCache($id);
            }

            return $deleted;
        });
    }

    public function paginate(
        int $perPage = 15,
        array $filters = [],
        array $with = []
    ): LengthAwarePaginator {
        $query = $this->model->with($with);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'like', "%{$filters['search']}%")
                  ->orWhere('content', 'like', "%{$filters['search']}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function getByType(string $type, array $with = []): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->with($with)->where('type', $type)->get()
        );
    }

    public function updateMeta(int $contentId, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $this->metaModel->updateOrCreate(
                ['content_id' => $contentId, 'key' => $key],
                ['value' => $value]
            );
        }
        
        $this->clearCache($contentId);
    }

    private function clearCache(int $contentId): void
    {
        $content = $this->model->find($contentId);
        
        if ($content) {
            Cache::forget(self::CACHE_PREFIX . $contentId);
            Cache::forget(self::CACHE_PREFIX . "slug:{$content->slug}");
            Cache::forget(self::CACHE_PREFIX . "type:{$content->type}");
        }
    }
}