<?php

namespace App\Core\Template\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;

class MediaGallery implements MediaGalleryInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private MediaStorageInterface $storage;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        MediaStorageInterface $storage
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->storage = $storage;
    }

    public function renderGallery(int $contentId, array $options = []): string 
    {
        $this->security->validateMediaAccess($contentId);
        
        $cacheKey = "media_gallery:{$contentId}:" . md5(serialize($options));
        
        return $this->cache->remember($cacheKey, function() use ($contentId, $options) {
            $media = $this->storage->getContentMedia($contentId);
            return $this->generateSecureGallery($media, $options);
        });
    }

    private function generateSecureGallery(array $media, array $options): string
    {
        $html = '<div class="media-gallery">';
        foreach ($media as $item) {
            if ($this->security->validateMediaItem($item)) {
                $html .= $this->renderSecureMediaItem($item, $options);
            }
        }
        $html .= '</div>';
        return $html;
    }

    private function renderSecureMediaItem($item, array $options): string
    {
        $url = $this->security->sanitizeUrl($item->getUrl());
        $alt = $this->security->sanitize($item->getAlt());
        $width = (int)($options['width'] ?? 300);
        $height = (int)($options['height'] ?? 200);

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" loading="lazy" class="media-item"/>',
            $url, $alt, $width, $height
        );
    }
}
