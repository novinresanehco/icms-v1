<?php

namespace App\Core\Template;

class TemplateEngine implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private ContentProcessor $processor;
    private AuditLogger $logger;

    public function render(string $template, array $data = []): string
    {
        try {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);
            
            // Get cached if available
            $cacheKey = $this->getCacheKey($template, $data);
            if ($cached = $this->getCached($cacheKey)) {
                return $cached;
            }

            // Process template
            $processed = $this->processTemplate($template, $data);
            
            // Cache result
            $this->cache->put($cacheKey, $processed);
            
            return $processed;
            
        } catch (\Exception $e) {
            $this->handleRenderFailure($template, $e);
            throw $e;
        }
    }

    private function processTemplate(string $template, array $data): string
    {
        // Process content blocks
        $content = $this->processor->processContent($template, $data);
        
        // Apply security filters
        $content = $this->security->sanitizeOutput($content);
        
        // Process includes
        $content = $this->processIncludes($content);
        
        return $content;
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new ValidationException('Invalid template');
        }
    }

    private function validateData(array $data): void
    {
        if (!$this->validator->validateTemplateData($data)) {
            throw new ValidationException('Invalid template data');
        }
    }
}

class ContentProcessor implements ContentProcessorInterface
{
    private SecurityManager $security;
    private MediaProcessor $media;
    private MarkdownParser $markdown;
    
    public function processContent(Content $content, array $context = []): ProcessedContent
    {
        // Process Markdown content
        $processed = $this->markdown->parse($content->body);
        
        // Process media references
        $processed = $this->media->processMedia($processed);
        
        // Apply security filters
        $processed = $this->security->sanitizeContent($processed);
        
        return new ProcessedContent($processed);
    }

    public function processGallery(array $media): string
    {
        return $this->media->renderGallery($media);
    }
}

class UIComponentFactory implements ComponentFactoryInterface
{
    private SecurityManager $security;
    private ComponentRegistry $registry;
    private ValidationService $validator;

    public function create(string $type, array $props = []): Component
    {
        // Validate component type
        if (!$this->registry->has($type)) {
            throw new ComponentException("Invalid component type: {$type}");
        }

        // Validate props
        $this->validateProps($type, $props);

        // Create component
        $component = $this->registry->get($type);
        $component->setProps($props);

        // Apply security context
        $this->applySecurityContext($component);

        return $component;
    }

    private function validateProps(string $type, array $props): void
    {
        $rules = $this->registry->getValidationRules($type);
        if (!$this->validator->validate($props, $rules)) {
            throw new ValidationException('Invalid component props');
        }
    }

    private function applySecurityContext(Component $component): void
    {
        $component->setSecurityContext($this->security->getCurrentContext());
    }
}