<?php

namespace App\Core\Display;

use App\Core\Template\TemplateEngineInterface;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Media\MediaManagerInterface;

class ContentDisplayManager implements DisplayManagerInterface
{
    private TemplateEngineInterface $templateEngine;
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private MediaManagerInterface $media;
    private array $config;

    public function __construct(
        TemplateEngineInterface $templateEngine,
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        MediaManagerInterface $media,
        array $config
    ) {
        $this->templateEngine = $templateEngine;
        $this->security = $security;
        $this->cache = $cache;
        $this->media = $media;
        $this->config = $config;
    }

    public function renderContent(Content $content, array $options = []): string 
    {
        $cacheKey = $this->generateCacheKey($content, $options);

        return $this->cache->remember($cacheKey, function() use ($content, $options) {
            $this->security->validateAccess($content);
            
            $processedContent = $this->processContent($content);
            $mediaContent = $this->processMedia($content->media);
            
            return $this->templateEngine->render('content.display', [
                'content' => $processedContent,
                'media' => $mediaContent,
                'options' => $this->validateOptions($options)
            ]);
        });
    }

    public function renderGallery(array $media, array $options = []): string
    {
        $cacheKey = "gallery:" . md5(serialize($media) . serialize($options));
        
        return $this->cache->remember($cacheKey, function() use ($media, $options) {
            $processedMedia = array_map(
                fn($item) => $this->media->process($item),
                $this->security->validateMedia($media)
            );
            
            return $this->templateEngine->render('gallery.display', [
                'media' => $processedMedia,
                'options' => $this->validateOptions($options)
            ]);
        });
    }

    private function processContent(Content $content): array
    {
        return [
            'title' => $this->security->sanitize($content->title),
            'body' => $this->security->sanitizeHtml($content->body),
            'metadata' => $this->processMetadata($content->metadata)
        ];
    }

    private function processMedia(array $media): array
    {
        return array_map(function($item) {
            return [
                'url' => $this->media->getSecureUrl($item),
                'type' => $item->type,
                'metadata' => $this->security->sanitize($item->metadata)
            ];
        }, $media);
    }

    private function processMetadata(array $metadata): array
    {
        return array_map(
            fn($value) => $this->security->sanitize($value),
            $metadata
        );
    }

    private function validateOptions(array $options): array
    {
        $defaults = [
            'layout' => 'default',
            'cache_ttl' => $this->config['default_cache_ttl'],
            'security_level' => $this->config['default_security_level']
        ];

        return array_merge($defaults, array_intersect_key(
            $options,
            $defaults
        ));
    }

    private function generateCacheKey(Content $content, array $options): string
    {
        return sprintf(
            'content:%s:display:%s',
            $content->id,
            md5(serialize($options))
        );
    }
}
