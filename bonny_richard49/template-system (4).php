<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Template\Repositories\TemplateRepository;
use App\Core\Template\Engines\TemplateEngine;
use App\Core\Template\Exceptions\{TemplateException, RenderException};

/**
 * Production Template System
 * Handles all template operations with security and caching
 */
class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private TemplateEngine $engine;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        TemplateEngine $engine,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->engine = $engine;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    /**
     * Render content using template with caching
     */
    public function render(string $template, array $data, SecurityContext $context): RenderResult
    {
        return $this->security->executeCriticalOperation(
            new RenderOperation($template, $data),
            $context,
            function() use ($template, $data) {
                // Generate cache key
                $cacheKey = $this->generateCacheKey($template, $data);
                
                // Check cache first
                if ($cached = $this->cache->get($cacheKey)) {
                    return new RenderResult($cached);
                }
                
                // Validate template and data
                $this->validateRenderInput($template, $data);
                
                // Load and compile template
                $compiledTemplate = $this->loadAndCompileTemplate($template);
                
                // Render with error handling
                try {
                    $rendered = $this->engine->render($compiledTemplate, $data);
                    
                    // Cache successful render
                    $this->cache->put($cacheKey, $rendered, config('template.cache_ttl'));
                    
                    return new RenderResult($rendered);
                    
                } catch (\Exception $e) {
                    throw new RenderException(
                        'Template rendering failed: ' . $e->getMessage(),
                        $e->getCode(),
                        $e
                    );
                }
            }
        );
    }

    /**
     * Load and compile template with caching
     */
    private function loadAndCompileTemplate(string $template): CompiledTemplate
    {
        $cacheKey = "compiled_template:{$template}";
        
        // Check compilation cache
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        // Load template
        $templateContent = $this->repository->getTemplate($template);
        
        // Compile template
        $compiled = $this->engine->compile($templateContent);
        
        // Cache compilation
        $this->cache->put($cacheKey, $compiled, config('template.compile_cache_ttl'));
        
        return $compiled;
    }

    /**
     * Create or update template
     */
    public function saveTemplate(string $name, string $content, SecurityContext $context): TemplateResult
    {
        return $this->security->executeCriticalOperation(
            new SaveTemplateOperation($name, $content),
            $context,
            function() use ($name, $content) {
                // Validate template
                $this->validator->validateTemplate($content);
                
                // Save template
                $template = $this->repository->save($name, $content);
                
                // Clear related caches
                $this->clearTemplateCaches($name);
                
                // Log template update
                Log::info('Template saved', ['name' => $name]);
                
                return new TemplateResult($template);
            }
        );
    }

    /**
     * Delete template with cache clearing
     */
    public function deleteTemplate(string $name, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteTemplateOperation($name),
            $context,
            function() use ($name) {
                // Delete template
                $result = $this->repository->delete($name);
                
                // Clear related caches
                $this->clearTemplateCaches($name);
                
                // Log deletion
                Log::info('Template deleted', ['name' => $name]);
                
                return $result;
            }
        );
    }

    /**
     * Clear all caches related to a template
     */
    private function clearTemplateCaches(string $name): void
    {
        // Clear compilation cache
        $this->cache->forget("compiled_template:{$name}");
        
        // Clear render caches with this template
        $pattern = "template_render:*{$name}*";
        foreach ($this->cache->getKeys($pattern) as $key) {
            $this->cache->forget($key);
        }
    }

    /**
     * Generate cache key for rendered content
     */
    private function generateCacheKey(string $template, array $data): string
    {
        return 'template_render:' . md5($template . serialize($data));
    }

    /**
     * Validate template render inputs
     */
    private function validateRenderInput(string $template, array $data): void
    {
        // Validate template exists
        if (!$this->repository->exists($template)) {
            throw new TemplateException("Template not found: {$template}");
        }
        
        // Validate data structure
        if (!$this->validator->validateTemplateData($data)) {
            throw new TemplateException('Invalid template data structure');
        }
    }

    /**
     * List all available templates
     */
    public function listTemplates(SecurityContext $context): TemplateListResult
    {
        return $this->security->executeCriticalOperation(
            new ListTemplatesOperation(),
            $context,
            function() {
                $templates = $this->repository->listAll();
                return new TemplateListResult($templates);
            }
        );
    }

    /**
     * Get template content for editing
     */
    public function getTemplateContent(string $name, SecurityContext $context): TemplateContentResult
    {
        return $this->security->executeCriticalOperation(
            new GetTemplateOperation($name),
            $context,
            function() use ($name) {
                $content = $this->repository->getTemplate($name);
                return new TemplateContentResult($content);
            }
        );
    }
}
