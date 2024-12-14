<?php

namespace App\Core\Media\Services;

use App\Core\Media\Models\Media;
use App\Core\Media\Repositories\MediaRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;

class MediaCacheService
{
    private const CACHE_TTL = 3600;
    private const CACHE_PREFIX = 'media:';

    public function __construct(private MediaRepository $repository)
    {
    }

    public function getMedia(int $id): ?Media
    {
        return Cache::tags(['media'])->remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn() => $this->repository->findWithVariants($id)
        );
    }

    public function getMediaByType(string $type, array $filters = []): Collection
    {
        $cacheKey = self::CACHE_PREFIX . "type:{$type}:" . md5(serialize($filters));

        return Cache::tags(['media', "type:{$type}"])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->findByType($type, $filters)
        );
    }

    public function getMediaStats(Media $media): array
    {
        return Cache::tags(['media', "media:{$media->id}"])->remember(
            self::CACHE_PREFIX . "stats:{$media->id}",
            self::CACHE_TTL,
            fn() => $this->repository->getUsageStats($media)
        );
    }

    public function invalidateMedia(Media $media): void
    {
        $tags = [
            'media',
            "media:{$media->id}",
            "type:" . $this->getMediaType($media)
        ];

        Cache::tags($tags)->flush();
    }

    public function invalidateAll(): void
    {
        Cache::tags(['media'])->flush();
    }

    protected function getMediaType(Media $media): string
    {
        if ($media->isImage()) {
            return 'image';
        }
        if ($media->isVideo()) {
            return 'video';
        }
        return 'document';
    }
}
