<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\TagRepositoryInterface;
use App\Core\Models\Tag;
use App\Core\Exceptions\TagRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TagRepository implements TagRepositoryInterface
{
    protected Tag $model;
    protected const CACHE_PREFIX = 'tag:';
    protected const CACHE_TTL = 3600;

    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'general',
                'is_featured' => $data['is_featured'] ?? false,
                'meta_title' => $data['meta_title'] ?? $data['name'],
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
            ]);

            DB::commit();
            $this->clearCache();

            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException("Failed to create tag: {$e->getMessage()}", 0, $e);
        }
    }

    public function createMultiple(array $tags): Collection
    {
        try {
            DB::beginTransaction();

            $createdTags = collect();

            foreach ($tags as $tagData) {
                $tag = $this->model->create([
                    'name' => $tagData['name'],
                    'slug' => $tagData['slug'] ?? str($tagData['name'])->slug(),
                    'type' => $tagData['type'] ?? 'general'
                ]);
                $createdTags->push($tag);
            }

            DB::commit();
            $this->clearCache();

            return $createdTags;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException("Failed to create multiple tags: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $tag = $this->findById($id);
            
            $tag->update([
                'name' => $data['name'] ?? $tag->name,
                'slug' => $data['slug'] ?? $tag->slug,
                'description' => $data['description'] ?? $tag->description,
                'type' => $data['type'] ?? $tag->type,
                'is_featured' => $data['is_featured'] ?? $tag->is_featured,
                'meta_title' => $data['meta_title'] ?? $tag->meta_title,
                'meta_description' => $data['meta_description'] ?? $tag->meta_description,
                'meta_keywords' => $data['meta_keywords'] ?? $tag->meta_keywords,
            ]);

            DB::commit();
            $this->clearCache();

            return $tag;
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException("Failed to update tag: {$e->getMessage()}", 0, $e);
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with('contents')->findOrFail($id)
        );
    }

    public function findBySlug(string $slug): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->with('contents')->where('slug', $slug)->firstOrFail()
        );
    }

    public function findByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('type', $type)->get()
        );
    }

    public function getFeatured(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'featured',
            self::CACHE_TTL,
            fn () => $this->model->where('is_featured', true)->get()
        );
    }

    public function getPopular(int $limit = 10): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "popular:{$limit}",
            self::CACHE_TTL,
            fn () => $this->model->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }

    public function search(string $term): Collection
    {
        return $this->model->where('name', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%")
            ->get();
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $tag = $this->findById($id);
            $tag->contents()->detach();
            $deleted = $tag->delete();

            DB::commit();
            $this->clearCache();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException("Failed to delete tag: {$e->getMessage()}", 0, $e);
        }
    }

    public function syncContentTags(int $contentId, array $tagIds): void
    {
        try {
            DB::beginTransaction();

            $content = app(ContentRepositoryInterface::class)->findById($contentId);
            $content->tags()->sync($tagIds);

            DB::commit();
            $this->clearCache();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException("Failed to sync content tags: {$e->getMessage()}", 0, $e);
        }
    }

    public function mergeTags(int $sourceId, int $targetId): Model
    {
        try {
            DB::beginTransaction();

            $source = $this->findById($sourceId);
            $target = $this->findById($targetId);

            // Move all content associations
            $source->contents->each(function ($content) use ($target) {
                $content->tags()->attach($target->id);
                $content->tags()->detach($source->id);
            });

            $source->delete();

            DB::commit();
            $this->clearCache();

            return $target;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException("Failed to merge tags: {$e->getMessage()}", 0, $e);
        }
    }

    protected function clearCache(): void
    {
        Cache::tags(['tags'])->flush();
    }
}
