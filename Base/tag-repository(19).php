<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\TagRepositoryInterface;
use App\Models\Tag;
use App\Exceptions\TagException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    /**
     * Cache TTL in seconds
     */
    protected const CACHE_TTL = 3600;

    /**
     * @param Tag $model
     */
    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    /**
     * {@inheritDoc}
     */
    public function findBySlug(string $slug): ?Tag
    {
        return Cache::remember("tag:slug:{$slug}", self::CACHE_TTL, function () use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getPopular(int $limit = 10): Collection
    {
        return Cache::remember("tags:popular:{$limit}", self::CACHE_TTL, function () use ($limit) {
            return $this->model->withCount('content')
                ->orderByDesc('content_count')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getWithContentCount(): Collection
    {
        return Cache::remember('tags:content_count', self::CACHE_TTL, function () {
            return $this->model->withCount('content')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag
    {
        try {
            DB::beginTransaction();

            $sourceTag = $this->findById($sourceTagId);
            $targetTag = $this->findById($targetTagId);

            if (!$sourceTag || !$targetTag) {
                throw new TagException("Source or target tag not found");
            }

            // Move all content associations to target tag
            DB::table('content_tag')
                ->where('tag_id', $sourceTagId)
                ->update(['tag_id' => $targetTagId]);

            // Delete source tag
            $sourceTag->delete();

            DB::commit();

            $this->clearTagCache();

            return $targetTag->fresh();
        } catch (QueryException $e) {
            DB::rollBack();
            throw new TagException("Error merging tags: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findOrCreateMany(array $tagNames): Collection
    {
        $tags = collect();

        try {
            DB::beginTransaction();

            foreach ($tagNames as $name) {
                $name = trim($name);
                if (empty($name)) continue;

                $tag = $this->model->firstOrCreate(
                    ['name' => $name],
                    ['slug' => Str::slug($name)]
                );

                $tags->push($tag);
            }

            DB::commit();

            $this->clearTagCache();

            return $tags;
        } catch (QueryException $e) {
            DB::rollBack();
            throw new TagException("Error creating tags: {$e->getMessage()}");
        }
    }

    /**
     * Clear tag cache
     *
     * @return void
     */
    protected function clearTagCache(): void
    {
        Cache::tags(['tags'])->flush();
    }
}
