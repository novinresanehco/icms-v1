<?php

namespace App\Core\Template\Render;

class ContentRenderer implements RenderInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidatorInterface $validator;
    private array $renderers = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidatorInterface $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(Content $content, array $options = []): string 
    {
        return DB::transaction(function() use ($content, $options) {
            $this->security->validateContent($content);
            $this->validator->validateRenderOptions($options);

            $cacheKey = $this->getCacheKey($content, $options);
            
            return $this->cache->remember($cacheKey, function() use ($content, $options) {
                return $this->processContent($content, $options);
            });
        });
    }

    private function processContent(Content $content, array $options): string 
    {
        $renderer = $this->getRenderer($content->getType());
        $processed = $renderer->process($content);
        return $this->applySecurityFilters($processed);
    }

    private function getRenderer(string $type): TypeRendererInterface 
    {
        if (!isset($this->renderers[$type])) {
            $this->renderers[$type] = match($type) {
                'html' => new HtmlRenderer($this->security),
                'markdown' => new MarkdownRenderer($this->security),
                'media' => new MediaRenderer($this->security),
                default => throw new UnsupportedContentType($type)
            };
        }
        return $this->renderers[$type];
    }

    private function applySecurityFilters(string $content): string 
    {
        return $this->security->sanitizeOutput(
            $this->security->validateOutput($content)
        );
    }

    private function getCacheKey(Content $content, array $options): string 
    {
        return 'content:' . md5(
            $content->getId() . 
            $content->getUpdatedAt()->format('U') . 
            serialize($options)
        );
    }
}

class HtmlRenderer implements TypeRendererInterface 
{
    private SecurityManager $security;
    
    public function process(Content $content): string 
    {
        $html = $content->getContent();
        $this->security->validateHtml($html);
        
        return $this->security->sanitizeHtml(
            $this->processIncludes($html)
        );
    }

    private function processIncludes(string $html): string 
    {
        return preg_replace_callback(
            '/{include:([^}]+)}/',
            fn($matches) => $this->validateAndLoadInclude($matches[1]),
            $html
        );
    }
}

class MarkdownRenderer implements TypeRendererInterface 
{
    private SecurityManager $security;
    
    public function process(Content $content): string 
    {
        $markdown = $content->getContent();
        $this->security->validateMarkdown($markdown);
        
        return $this->security->sanitizeHtml(
            (new MarkdownConverter())->convert($markdown)
        );
    }
}

class MediaRenderer implements TypeRendererInterface 
{
    private SecurityManager $security;
    
    public function process(Content $content): string 
    {
        $media = $content->getContent();
        $this->security->validateMedia($media);
        
        return view('components.media', [
            'media' => $media,
            'options' => $this->security->getMediaOptions($media)
        ])->render();
    }
}

interface RenderInterface 
{
    public function render(Content $content, array $options = []): string;
}

interface TypeRendererInterface 
{
    public function process(Content $content): string;
}
