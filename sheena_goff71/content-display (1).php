<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Content\ContentManagerInterface;

class ContentDisplay implements ContentDisplayInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ContentManagerInterface $content;
    private TemplateEngineInterface $templates;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ContentManagerInterface $content,
        TemplateEngineInterface $templates
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->content = $content;
        $this->templates = $templates;
    }

    /**
     * Renders content securely with proper template and caching
     */
    public function displayContent(int $contentId, string $template = null): string
    {
        // Security and access check
        $this->security->validateContentAccess($contentId);
        
        // Get content with caching
        $content = $this->content->getWithCache($contentId);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$contentId}");
        }
        
        // Determine template
        $template = $template ?? $content->getTemplate() ?? 'default';
        
        // Prepare display data with security context
        $data = [
            'content' => $content,
            'security' => $this->security->getDisplayContext(),
            'meta' => $this->prepareMetadata($content)
        ];
        
        // Render through template engine with all security controls
        return $this->templates->render($template, $data);
    }

    /**
     * Prepares content metadata for display
     */
    private function prepareMetadata($content): array
    {
        return [
            'title' => $this->security->sanitize($content->getTitle()),
            'description' => $this->security->sanitize($content->getDescription()),
            'created_at' => $content->getCreatedAt()->format('Y-m-d H:i:s'),
            'author' => $this->security->sanitize($content->getAuthor()),
            'type' => $content->getType()
        ];
    }
}

interface ContentDisplayInterface
{
    public function displayContent(int $contentId, string $template = null): string;
}

class ContentNotFoundException extends \RuntimeException {}
