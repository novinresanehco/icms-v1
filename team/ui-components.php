<?php

namespace App\Core\UI;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Media\MediaManagerInterface;
use App\Core\Cache\CacheManagerInterface;

class ContentDisplayManager implements DisplayManagerInterface 
{
    private SecurityManagerInterface $security;
    private MediaManagerInterface $media;
    private CacheManagerInterface $cache;

    public function __construct(
        SecurityManagerInterface $security,
        MediaManagerInterface $media,
        CacheManagerInterface $cache
    ) {
        $this->security = $security;
        $this->media = $media;
        $this->cache = $cache;
    }

    public function renderContent(string $content, array $options = []): string 
    {
        $this->security->validateAccess('content.render');

        return $this->cache->remember("content.{$content}", 3600, function() use ($content, $options) {
            return $this->processContent($content, $options);
        });
    }

    public function renderMedia(int $mediaId, array $params = []): string 
    {
        $this->security->validateAccess('media.render', $mediaId);

        return $this->cache->remember("media.{$mediaId}", 3600, function() use ($mediaId, $params) {
            $media = $this->media->get($mediaId);
            return $this->processMedia($media, $params);
        });
    }

    public function renderGallery(array $mediaIds, array $options = []): string 
    {
        $this->security->validateAccess('gallery.render');

        return $this->cache->remember(
            "gallery." . md5(serialize($mediaIds)), 
            3600, 
            function() use ($mediaIds, $options) {
                return $this->processGallery($mediaIds, $options);
            }
        );
    }

    protected function processContent(string $content, array $options): string 
    {
        $sanitized = $this->sanitizeContent($content);
        return view('components.content', [
            'content' => $sanitized,
            'options' => $options
        ])->render();
    }

    protected function processMedia($media, array $params): string 
    {
        $validated = $this->validateMediaParams($params);
        return view('components.media', [
            'media' => $media,
            'params' => $validated
        ])->render();
    }

    protected function processGallery(array $mediaIds, array $options): string 
    {
        $media = $this->media->getMultiple($mediaIds);
        $validated = $this->validateGalleryOptions($options);
        
        return view('components.gallery', [
            'media' => $media,
            'options' => $validated
        ])->render();
    }

    protected function sanitizeContent(string $content): string 
    {
        return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    }

    protected function validateMediaParams(array $params): array 
    {
        $defaults = [
            'width' => 800,
            'height' => 600,
            'quality' => 90
        ];

        return array_merge($defaults, array_intersect_key($params, $defaults));
    }

    protected function validateGalleryOptions(array $options): array 
    {
        $defaults = [
            'columns' => 3,
            'thumbSize' => 200,
            'lightbox' => true
        ];

        return array_merge($defaults, array_intersect_key($options, $defaults));
    }
}

interface DisplayManagerInterface 
{
    public function renderContent(string $content, array $options = []): string;
    public function renderMedia(int $mediaId, array $params = []): string;
    public function renderGallery(array $mediaIds, array $options = []): string;
}
