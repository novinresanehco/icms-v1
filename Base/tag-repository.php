<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Interfaces\TagRepositoryInterface;

class TagRepository implements TagRepositoryInterface
{
    private const CACHE_PREFIX = 'tag:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Tag $model
    ) {}

    public function findById(int $id): ?Tag
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->find($id)
        );
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->where('slug', $slug)->first()
        );
    }

    public function getAll(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            fn () => $this->model->orderBy('name')->get()
        );
    }

    public function getPopular(int $limit = 10): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "popular:{$limit}",
            self::CACHE_TTL,
            fn () => $this->model
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }

    public function create(array $data): Tag
    {
        $tag = $this->model->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'general'
        ]);

        $this->clearCache();

        return $tag;
    }

    public function update(int $id, array $data): bool
    {
        $tag = $this->findById($id);
        
        if (!$tag) {
            return false;
        }

        $updated = $tag->update([
            'name' => $data['name'] ?? $tag->name,
            'slug' => $data['slug'] ?? $tag->slug,
            'description' => $data['description'] ?? $tag->description,
            'type' => $data['type'] ?? $tag->type
        ]);

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $tag = $this->findById($id);
        
        if (!$tag) {
            return false;
        }

        $tag->contents()->detach();
        $deleted = $tag->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function findOrCreate(string $name): Tag
    {
        $tag = $this->model->where('name', $name)->first();

        if (!$tag) {
            $tag = $this->create([
                'name' => $name,
                'slug' => str_slug($name)
            ]);
        }

        return $tag;
    }

    public function findByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('type', $type)->get()
        );
    }

    protected function clearCache(): void
    {
        $keys = ['all'];
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}