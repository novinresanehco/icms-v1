<?php

namespace App\Repositories;

use App\Models\Content;
use App\Models\ContentVersion;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentRepository implements ContentRepositoryInterface
{
    public function __construct(
        protected Content $model,
        protected ContentVersion $versionModel
    ) {}

    public function create(array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->model->create($data);
            
            if (isset($data['categories'])) {
                $content->categories()->attach($data['categories']);
            }
            
            if (isset($data['tags'])) {
                $content->tags()->attach($data['tags']);
            }
            
            $this->createVersion($content->id, $data);
            
            DB::commit();
            $this->clearContentCache();
            
            return $content->fresh(['categories', 'tags']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create content: ' . $e->getMessage());
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->find($id);
            if (!$content) {
                throw new \Exception('Content not found');
            }

            $content->update($data);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->createVersion($content->id, $data);

            DB::commit();
            $this->clearContentCache($content->slug);

            return $content->fresh(['categories', 'tags']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update content: ' . $e->getMessage());
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = $this->find($id);
            if (!$content) {
                throw new \Exception('Content not found');
            }

            $content->categories()->detach();
            $content->tags()->detach();
            $content->delete();

            DB::commit();
            $this->clearContentCache($content->slug);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete content: ' . $e->getMessage());
            throw $e;
        }
    }

    public function find(int $id): ?Content
    {
        return Cache::remember(
            "content.{$id}",
            config('cache.ttl', 3600),
            fn() => $this->model->with(['categories', 'tags'])->find($id)
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::remember(
            "content.slug.{$slug}",
            config('cache.ttl', 3600),
            fn() => $this->model->with(['categories', 'tags'])
                ->where('slug', $slug)
                ->where('status', 'published')
                ->first()
        );
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->with(['categories', 'tags'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getPublished(): Collection
    {
        return Cache::remember(
            'content.published',
            config('cache.ttl', 3600),
            fn() => $this->model->with(['categories', 'tags'])
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function getDrafts(): Collection
    {
        return $this->model->with(['categories', 'tags'])
            ->where('status', 'draft')
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function search(string $query): Collection
    {
        return $this->model->with(['categories', 'tags'])
            ->where(function (Builder $builder) use ($query) {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('content', 'like', "%{$query}%");
            })
            ->where('status', 'published')
            ->get();
    }

    public function findByType(string $type): Collection
    {
        return Cache::remember(
            "content.type.{$type}",
            config('cache.ttl', 3600),
            fn() => $this->model->with(['categories', 'tags'])
                ->where('type', $type)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function getByCategory(int $categoryId): Collection
    {
        return Cache::remember(
            "content.category.{$categoryId}",
            config('cache.ttl', 3600),
            fn() => $this->model->with(['categories', 'tags'])
                ->whereHas('categories', function (Builder $query) use ($categoryId) {
                    $query->where('id', $categoryId);
                })
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function getByTags(array $tags): Collection
    {
        $cacheKey = 'content.tags.' . implode('.', $tags);
        return Cache::remember(
            $cacheKey,
            config('cache.ttl', 3600),
            fn() => $this->model->with(['categories', 'tags'])
                ->whereHas('tags', function (Builder $query) use ($tags) {
                    $query->whereIn('name', $tags);
                })
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function createVersion(int $contentId, array $data): bool
    {
        return $this->versionModel->create([
            'content_id' => $contentId,
            'data' => $data,
            'created_by' => auth()->id()
        ]) !== null;
    }

    public function getVersions(int $contentId): Collection
    {
        return $this->versionModel
            ->where('content_id', $contentId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function revertToVersion(int $contentId, int $versionId): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->find($contentId);
            $version = $this->versionModel->findOrFail($versionId);

            if ($version->content_id !== $contentId) {
                throw new \Exception('Version does not belong to this content');
            }

            $versionData = $version->data;
            $content->update($versionData);

            if (isset($versionData['categories'])) {
                $content->categories()->sync($versionData['categories']);
            }

            if (isset($versionData['tags'])) {
                $content->tags()->sync($versionData['tags']);
            }

            DB::commit();
            $this->clearContentCache($content->slug);

            return $content->fresh(['categories', 'tags']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to revert content version: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function clearContentCache(?string $slug = null): void
    {
        Cache::tags(['content'])->flush();
        if ($slug) {
            Cache::forget("content.slug.{$slug}");
        }
    }
}
