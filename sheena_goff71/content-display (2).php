<?php

namespace App\Core\Template\Display;

class ContentDisplayManager
{
    private TemplateEngine $template;
    private MediaHandler $media;
    private SecurityManager $security;
    private CacheManager $cache;

    public function __construct(
        TemplateEngine $template,
        MediaHandler $media,
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->template = $template;
        $this->media = $media;
        $this->security = $security;
        $this->cache = $cache;
    }

    public function renderContent(Content $content, string $template = 'content.default'): string 
    {
        return $this->cache->remember("content.{$content->id}", function() use ($content, $template) {
            DB::beginTransaction();
            try {
                $data = [
                    'content' => $this->prepareContent($content),
                    'media' => $this->media->getContentMedia($content),
                    'meta' => $this->getMetadata($content)
                ];
                
                $rendered = $this->template->render($template, $data);
                DB::commit();
                return $rendered;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function renderMediaGallery(array $media, string $template = 'media.gallery'): string 
    {
        $validated = $this->media->validateMediaItems($media);
        return $this->template->render($template, ['items' => $validated]);
    }

    protected function prepareContent(Content $content): array 
    {
        return [
            'body' => $this->security->sanitize($content->body),
            'title' => $this->security->sanitize($content->title),
            'timestamp' => $content->created_at,
            'author' => $this->security->sanitize($content->author)
        ];
    }

    protected function getMetadata(Content $content): array 
    {
        return [
            'id' => $content->id,
            'type' => $content->type,
            'status' => $content->status,
            'permissions' => $this->security->getContentPermissions($content)
        ];
    }
}

class MediaHandler
{
    private SecurityManager $security;
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    public function getContentMedia(Content $content): array 
    {
        return array_map(
            fn($media) => $this->processMediaItem($media),
            $content->media
        );
    }

    public function validateMediaItems(array $items): array 
    {
        return array_filter($items, fn($item) => 
            $this->security->validateMedia($item) && 
            in_array($item->type, $this->allowedTypes)
        );
    }

    private function processMediaItem(Media $media): array 
    {
        return [
            'url' => $this->security->sanitizeUrl($media->url),
            'type' => $media->type,
            'alt' => $this->security->sanitize($media->alt),
            'title' => $this->security->sanitize($media->title)
        ];
    }
}
