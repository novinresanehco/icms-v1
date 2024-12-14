<?php

namespace App\Core\Services;

use App\Core\Models\Tag;
use App\Core\Services\Contracts\TagServiceInterface;
use App\Core\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TagService implements TagServiceInterface
{
    public function __construct(
        private TagRepositoryInterface $repository
    ) {}

    public function findOrCreateTags(array $tagNames): Collection
    {
        return Cache::tags(['tags'])->remember(
            'tags.' . md5(implode(',', $tagNames)),
            now()->addHour(),
            fn() => $this->repository->syncTags($tagNames)
        );
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return Cache::tags(['tags'])->remember(
            "tags.popular.{$limit}",
            now()->addHour(),
            fn() => $this->repository->getPopular($limit)
        );
    }

    public function getRelatedTags(int $tagId, int $limit = 5): Collection
    {
        return Cache::tags(['tags'])->remember(
            "tags.related.{$tagId}.{$limit}",
            now()->addHour(),
            fn() => $this->repository->getRelated($tagId, $limit)
        );
    }

    public function createTag(array $data): Tag
    {
        $tag = $this->repository->store($data);
        Cache::tags(['tags'])->flush();
        return $tag;
    }

    public function updateTag(int $id, array $data): Tag
    {
        $tag = $this->repository->update($id, $data);
        Cache::tags(['tags'])->flush();
        return $tag;
    }

    public function deleteTag(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['tags'])->flush();
        return $result;
    }
}
