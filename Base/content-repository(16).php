<?php

namespace App\Core\Repositories;

use App\Models\Content;
use Illuminate\Support\Collection;
use App\Core\Services\Cache\CacheService;

class ContentRepository extends AdvancedRepository
{
    protected $model = Content::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->executeQuery(function() use ($slug) {
            return $this->cache->remember("content.slug.{$slug}", function() use ($slug) {
                return $this->model
                    ->where('slug', $slug)
                    ->with(['author', 'category', 'tags'])
                    ->first();
            });
        });
    }

    public function getPublished(array $filters = []): Collection
    {
        return $this->executeQuery(function() use ($filters) {
            return $this->model
                ->published()
                ->filter($filters)
                ->with(['author', 'category'])
                ->orderBy('published_at', 'desc')
                ->get();
        });
    }

    public function createVersion(Content $content): void
    {
        $this->executeTransaction(function() use ($content) {
            $content->versions()->create([
                'content' => $content->toArray(),
                'user_id' => auth()->id(),
                'created_at' => now()
            ]);
        });
    }

    public function restoreVersion(Content $content, int $versionId): void
    {
        $this->executeTransaction(function() use ($content, $versionId) {
            $version = $content->versions()->findOrFail($versionId);
            $content->update($version->content);
            $this->cache->forget("content.{$content->id}");
        });
    }
}
