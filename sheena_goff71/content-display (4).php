<?php

namespace App\Core\Display;

class ContentDisplayManager
{
    private SecurityValidator $validator;
    private TemplateEngine $templateEngine;
    private CacheManager $cache;

    private array $contentTypes = [
        'article' => ArticleRenderer::class,
        'page' => PageRenderer::class,
        'gallery' => GalleryRenderer::class,
        'media' => MediaRenderer::class
    ];

    public function __construct(
        SecurityValidator $validator,
        TemplateEngine $templateEngine,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->templateEngine = $templateEngine;
        $this->cache = $cache;
    }

    public function display(string $type, array $content, array $options = []): string
    {
        $this->validator->validateContentType($type);
        $this->validator->validateContent($content);

        $cacheKey = $this->generateCacheKey($type, $content, $options);

        return $this->cache->remember($cacheKey, fn() =>
            $this->renderContent($type, $content, $options)
        );
    }

    private function renderContent(string $type, array $content, array $options): string
    {
        $renderer = $this->getRenderer($type);
        $processedContent = $renderer->process($content, $options);
        
        return $this->templateEngine->render(
            $renderer->getTemplate(),
            $processedContent,
            $this->buildRenderOptions($options)
        );
    }

    private function getRenderer(string $type): ContentRendererInterface
    {
        if (!isset($this->contentTypes[$type])) {
            throw new ContentDisplayException("Invalid content type: $type");
        }

        return new $this->contentTypes[$type](
            $this->validator,
            $this->cache
        );
    }

    private function generateCacheKey(string $type, array $content, array $options): string
    {
        return sprintf(
            'content:%s:%s:%s',
            $type,
            md5(serialize($content)),
            md5(serialize($options))
        );
    }

    private function buildRenderOptions(array $options): array
    {
        return array_merge([
            'secure' => true,
            'cache' => true,
            'version' => 'v1'
        ], $options);
    }
}

interface ContentRendererInterface
{
    public function process(array $content, array $options): array;
    public function getTemplate(): string;
}

class ArticleRenderer implements ContentRendererInterface
{
    private SecurityValidator $validator;
    private CacheManager $cache;

    public function __construct(
        SecurityValidator $validator,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function process(array $content, array $options): array
    {
        return [
            'title' => $this->validator->sanitizeString($content['title']),
            'body' => $this->processBody($content['body']),
            'meta' => $this->processMeta($content['meta'] ?? []),
            'layout' => $options['layout'] ?? 'default'
        ];
    }

    public function getTemplate(): string
    {
        return 'content.article';
    }

    private function processBody(string $body): string
    {
        return $this->validator->sanitizeHtml($body);
    }

    private function processMeta(array $meta): array
    {
        return array_map(
            fn($value) => $this->validator->sanitizeString($value),
            $meta
        );
    }
}

class PageRenderer implements ContentRendererInterface
{
    private SecurityValidator $validator;
    private CacheManager $cache;

    public function __construct(
        SecurityValidator $validator,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function process(array $content, array $options): array
    {
        return [
            'title' => $this->validator->sanitizeString($content['title']),
            'sections' => array_map(
                fn($section) => $this->processSection($section),
                $content['sections']
            ),
            'layout' => $options['layout'] ?? 'page',
            'meta' => $this->processMeta($content['meta'] ?? [])
        ];
    }

    public function getTemplate(): string
    {
        return 'content.page';
    }

    private function processSection(array $section): array
    {
        return [
            'type' => $section['type'],
            'content' => $this->validator->sanitizeHtml($section['content']),
            'options' => $section['options'] ?? []
        ];
    }

    private function processMeta(array $meta): array
    {
        return array_map(
            fn($value) => $this->validator->sanitizeString($value),
            $meta
        );
    }
}
