<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected array $searchableFields = ['name', 'slug'];
    protected array $filterableFields = ['type', 'status'];

    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    public function findByNames(array $names): Collection
    {
        try {
            $query = $this->model->whereIn('name', $names);
            return $query->get();
        } catch (\Exception $e) {
            Log::error('Failed to find tags by names: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function syncTags(string $type, array $names): Collection
    {
        try {
            DB::beginTransaction();

            $existingTags = $this->findByNames($names);
            $existingNames = $existingTags->pluck('name')->toArray();
            $newNames = array_diff($names, $existingNames);

            // Create new tags
            foreach ($newNames as $name) {
                $tag = $this->create([
                    'name' => $name,
                    'type' => $type,
                    'slug' => Str::slug($name)
                ]);
                $existingTags->push($tag);
            }

            DB::commit();
            $this->clearModelCache();

            return $existingTags;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to sync tags: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getPopular(int $limit = 20): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("popular.{$limit}"),
                $this->cacheTTL,
                fn() => $this->model->withCount('contents')
                    ->having('contents_count', '>', 0)
                    ->orderByDesc('contents_count')
                    ->limit($limit)
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get popular tags: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getRelated(int $tagId, int $limit = 10): Collection
    {
        try {
            $tag = $this->find($tagId);
            if (!$tag) {
                return new Collection();
            }

            return $this->model->whereHas('contents', function ($query) use ($tag) {
                $query->whereIn('content_id', $tag->contents->pluck('id'));
            })
            ->where('id', '!=', $tagId)
            ->withCount('contents')
            ->orderByDesc('contents_count')
            ->limit($limit)
            ->get();
        } catch (\Exception $e) {
            Log::error('Failed to get related tags: ' . $e->getMessage());
            return new Collection();
        }
    }
}
