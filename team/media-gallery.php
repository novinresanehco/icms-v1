<?php

namespace App\Core\UI\Components;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Media\MediaManagerInterface;

class MediaGallery implements ComponentInterface
{
    private SecurityManagerInterface $security;
    private MediaManagerInterface $media;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MediaManagerInterface $media,
        array $config = []
    ) {
        $this->security = $security;
        $this->media = $media;
        $this->config = $this->validateConfig($config);
    }

    public function render(array $items): string
    {
        $this->security->validateAccess('gallery.render');

        $processedItems = array_map(
            fn($item) => $this->processGalleryItem($item),
            $items
        );

        return view('components.media-gallery', [
            'items' => $processedItems,
            'config' => $this->config
        ])->render();
    }

    protected function processGalleryItem(array $item): array
    {
        $this->security->validateAccess('media.process', $item['id']);

        return [
            'id' => $item['id'],
            'url' => $this->media->getSecureUrl($item['id']),
            'thumb' => $this->media->getSecureThumb($item['id']),
            'title' => htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'type' => $item['type'] ?? 'image'
        ];
    }

    protected function validateConfig(array $config): array
    {
        $defaults = [
            'thumbnailSize' => 200,
            'maxColumns' => 4,
            'lightbox' => true,
            'lazyLoad' => true
        ];

        return array_merge($defaults, array_intersect_key($config, $defaults));
    }
}
