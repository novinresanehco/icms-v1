<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;

class MediaGalleryManager implements MediaManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function process(MediaItem $item): ProcessedMedia
    {
        $cacheKey = "media:processed:{$item->id}";

        return $this->cache->remember($cacheKey, function() use ($item) {
            $this->security->validateMedia($item);
            
            return new ProcessedMedia(
                url: $this->generateSecureUrl($item),
                thumbnails: $this->generateThumbnails($item),
                metadata: $this->processMetadata($item->metadata)
            );
        });
    }

    public function getSecureUrl(MediaItem $item): string
    {
        return $this->security->signUrl(
            $this->generateMediaUrl($item),
            $this->config['url_expiration']
        );
    }

    private function generateThumbnails(MediaItem $item): array
    {
        return array_map(
            fn($size) => $this->generateThumbnail($item, $size),
            $this->config['thumbnail_sizes']
        );
    }

    private function generateThumbnail(MediaItem $item, array $size): string
    {
        $cacheKey = "thumbnail:{$item->id}:{$size['width']}x{$size['height']}";

        return $this->cache->remember($cacheKey, function() use ($item, $size) {
            return $this->security->signUrl(
                $this->generateThumbnailUrl($item, $size),
                $this->config['thumbnail_url_expiration']
            );
        });
    }

    private function processMetadata(array $metadata): array
    {
        return array_map(
            fn($value) => $this->security->sanitize($value),
            $metadata
        );
    }

    private function generateMediaUrl(MediaItem $item): string
    {
        return sprintf(
            '%s/media/%s/%s',
            $this->config['media_base_url'],
            $item->type,
            $item->filename
        );
    }

    private function generateThumbnailUrl(MediaItem $item, array $size): string
    {
        return sprintf(
            '%s/thumbnail/%s/%dx%d/%s',
            $this->config['media_base_url'],
            $item->type,
            $size['width'],
            $size['height'],
            $item->filename
        );
    }
}
