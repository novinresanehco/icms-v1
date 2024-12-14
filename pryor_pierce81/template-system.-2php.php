<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

final class TemplateEngine
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $compiledTemplates = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(string $template, array $data): string
    {
        try {
            $this->security->validateTemplateAccess($template);
            
            return $this->cache->remember("template.$template", function() use ($template, $data) {
                $compiled = $this->compile($template);
                return $this->renderCompiled($compiled, $data);
            });
        } catch (\Throwable $e) {
            $this->handleError($e, $template);
            throw $e;
        }
    }

    private function compile(string $template): CompiledTemplate
    {
        if (isset($this->compiledTemplates[$template])) {
            return $this->compiledTemplates[$template];
        }

        $source = $this->loadTemplate($template);
        $compiled = $this->validator->validateAndCompile($source);
        $this->compiledTemplates[$template] = $compiled;
        
        return $compiled;
    }
}

final class MediaGallery
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MediaProcessor $processor;

    public function render(array $media): string
    {
        $this->security->validateMediaAccess($media);
        
        return $this->cache->remember("gallery." . $this->getCacheKey($media), function() use ($media) {
            return $this->processor->optimizeAndRender($media);
        });
    }
}

final class ContentDisplay
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ContentFormatter $formatter;

    public function display(Content $content): string
    {
        $this->security->validateContentAccess($content);
        
        return $this->cache->remember("content.{$content->id}", function() use ($content) {
            return $this->formatter->format($content);
        });
    }
}

final class UIComponents
{
    private ComponentRegistry $registry;
    private SecurityManager $security;
    private ValidationService $validator;

    public function render(string $component, array $props): string
    {
        $this->security->validateComponentAccess($component);
        $this->validator->validateProps($component, $props);
        
        return $this->registry->get($component)->render($props);
    }
}

interface CompiledTemplate
{
    public function render(array $data): string;
}

interface MediaProcessor 
{
    public function optimizeAndRender(array $media): string;
}

interface ContentFormatter
{
    public function format(Content $content): string;
}

final class ComponentRegistry
{
    private array $components = [];
    
    public function register(string $name, UIComponent $component): void
    {
        $this->components[$name] = $component;
    }
    
    public function get(string $name): UIComponent
    {
        if (!isset($this->components[$name])) {
            throw new ComponentNotFoundException($name);
        }
        return $this->components[$name];
    }
}

interface UIComponent 
{
    public function render(array $props): string;
}
