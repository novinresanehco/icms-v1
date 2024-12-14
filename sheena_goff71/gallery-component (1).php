<?php

namespace App\Core\Template\Components;

class MediaGallery implements ComponentInterface
{
    private MediaRepository $media;
    private CacheManager $cache;

    public function render(array $mediaIds): string
    {
        return $this->cache->remember('gallery.' . implode('.', $mediaIds), function() use ($mediaIds) {
            $items = $this->media->findMany($mediaIds);
            return $this->generateGallery($items);
        });
    }

    private function generateGallery(array $items): string
    {
        $output = '<div class="media-gallery">';
        foreach ($items as $item) {
            $output .= $this->generateGalleryItem($item);
        }
        $output .= '</div>';
        return $output;
    }

    private function generateGalleryItem(Media $item): string
    {
        return sprintf(
            '<div class="gallery-item" data-id="%d">
                <img src="%s" alt="%s" loading="lazy">
            </div>',
            $item->id,
            $item->url,
            htmlspecialchars($item->title)
        );
    }
}
