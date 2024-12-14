<?php

namespace App\Core\Template\Media;

class MediaGalleryManager
{
    private MediaRepository $repository;
    private CacheManager $cache;
    private SecurityService $security;

    public function render(array $config): string
    {
        $mediaIds = $this->security->validateIds($config['items'] ?? []);
        
        return $this->cache->remember("gallery." . md5(serialize($mediaIds)), function() use ($mediaIds, $config) {
            $items = $this->repository->findMany($mediaIds);
            return $this->buildGallery($items, $config);
        });
    }

    private function buildGallery(array $items, array $config): string
    {
        $layout = $this->getGalleryLayout($config['layout'] ?? 'grid');
        $html = "<div class=\"gallery $layout\">";
        
        foreach ($items as $item) {
            $html .= $this->renderItem($item, $config);
        }
        
        $html .= '</div>';
        return $html;
    }

    private function renderItem(Media $item, array $config): string
    {
        $size = $this->getImageSize($config['size'] ?? 'medium');
        return sprintf(
            '<div class="gallery-item" data-id="%d">
                <img src="%s" width="%d" height="%d" alt="%s" loading="lazy">
            </div>',
            $item->id,
            $this->security->validateUrl($item->url),
            $size['width'],
            $size['height'],
            htmlspecialchars($item->title)
        );
    }

    private function getImageSize(string $size): array
    {
        return [
            'thumbnail' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 300, 'height' => 300],
            'large' => ['width' => 800, 'height' => 600]
        ][$size] ?? ['width' => 300, 'height' => 300];
    }

    private function getGalleryLayout(string $layout): string
    {
        return in_array($layout, ['grid', 'masonry', 'slider']) ? $layout : 'grid';
    }
}

class MediaRenderer
{
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    private SecurityService $security;

    public function render(Media $media, array $options = []): string
    {
        if (!in_array($media->type, $this->allowedTypes)) {
            return '';
        }

        $url = $this->security->validateUrl($media->url);
        $size = $this->getImageSize($options['size'] ?? 'medium');
        
        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s" class="media" loading="lazy">',
            $url,
            $size['width'],
            $size['height'],
            htmlspecialchars($media->title)
        );
    }

    private function getImageSize(string $size): array
    {
        return [
            'thumbnail' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 300, 'height' => 300],
            'large' => ['width' => 800, 'height' => 600]
        ][$size] ?? ['width' => 300, 'height' => 300];
    }
}

class Media
{
    public int $id;
    public string $url;
    public string $type;
    public string $title;
}

interface MediaRepository
{
    public function findMany(array $ids): array;
    public function find(int $id): ?Media;
}
