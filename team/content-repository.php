<?php

namespace App\Core\Repositories;

use App\Models\Content;
use App\Core\Cache\CacheManager;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\{RepositoryException, ValidationException};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ContentRepository implements RepositoryInterface
{
    protected Content $model;
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected ValidationService $validator;

    public function __construct(
        Content $model,
        CacheManager $cache,
        SecurityManager $security,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
    }

    public function findOrFail(int $id): Content
    {
        return $this->cache->tags(['content'])->remember(
            $this->getCacheKey($id),
            config('cache.ttl'),
            function() use ($id) {
                $content = $this->model->findOrFail($id);
                $this->security->validateAccess($content);
                return $content;
            }
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->tags(['content'])->remember(
            "content.slug.{$slug}",
            config('cache.ttl'),
            function() use ($slug) {
                $content = $this->model->where('slug', $slug)->first();
                if ($content) {
                    $this->security->validateAccess($content);
                }
                return $content;
            }
        );
    }

    public function create(array $data): Content
    {
        $this->validateData($data);

        DB::beginTransaction();
        try {
            $content = $this->model->create([
                'title' => $data['title'],
                'slug' => $this->generateSlug($data['title']),
                'content' => $data['content'],
                'status' => $data['status'],
                'user_id' => $data['user_id'],
                'metadata' => $data['metadata'] ?? [],
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->cache->tags(['content'])->put(
                $this->getCacheKey($content->id),
                $content,
                config('cache.ttl')
            );

            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Content creation failed: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): Content
    {
        $this->validateData($data, $id);

        DB::beginTransaction();
        try {
            $content = $this->findOrFail($id);
            
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'slug' => isset($data['title']) ? $this->generateSlug($data['title'], $id) : $content->slug,
                'content' => $data['content'] ?? $content->content,
                'status' => $data['status'] ?? $content->status,
                'metadata' => array_merge($content->metadata ?? [], $data['metadata'] ?? []),
                'updated_at' => now()
            ]);

            $this->cache->tags(['content'])->put(
                $this->getCacheKey($content->id),
                $content,
                config('cache.ttl')
            );

            if ($content->wasChanged('slug')) {
                $this->cache->tags(['content'])->forget("content.slug.{$content->getOriginal('slug')}");
            }

            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Content update failed: ' . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = $this->findOrFail($id);
            $content->delete();

            $this->cache->tags(['content'])->forget($this->getCacheKey($id));
            $this->cache->tags(['content'])->forget("content.slug.{$content->slug}");

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Content deletion failed: ' . $e->getMessage());
        }
    }

    public function getPublished(array $criteria = [], array $relations = []): Collection
    {
        $cacheKey = "content.published." . md5(serialize($criteria) . serialize($relations));

        return $this->cache->tags(['content'])->remember(
            $cacheKey,
            config('cache.ttl'),
            function() use ($criteria, $relations) {
                $query = $this->model->published();

                if (!empty($criteria)) {
                    $query->where($criteria);
                }

                if (!empty($relations)) {
                    $query->with($relations);
                }

                return $query->get();
            }
        );
    }

    protected function validateData(array $data, ?int $id = null): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'user_id' => 'required|exists:users,id',
            'metadata' => 'array'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Content validation failed');
        }
    }

    protected function generateSlug(string $title, ?int $id = null): string
    {
        $slug = str_slug($title);
        $count = 0;

        while (true) {
            $currentSlug = $count ? "{$slug}-{$count}" : $slug;
            $query = $this->model->where('slug', $currentSlug);
            
            if ($id) {
                $query->where('id', '!=', $id);
            }

            if (!$query->exists()) {
                return $currentSlug;
            }

            $count++;
        }
    }

    protected function getCacheKey(int $id): string
    {
        return "content.{$id}";
    }
}
