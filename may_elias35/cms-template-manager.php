<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;

    private const TEMPLATE_CACHE_TTL = 3600;
    private const COMPILATION_TIMEOUT = 30;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function renderTemplate(string $template, array $data, SecurityContext $context): RenderedContent
    {
        try {
            // Validate context
            $this->security->validateContext($context);
            
            // Load template
            $templateContent = $this->loadTemplate($template);
            
            // Validate data
            $this->validateTemplateData($data, $templateContent);
            
            // Compile template
            $compiled = $this->compileTemplate($templateContent, $data);
            
            // Render content
            $rendered = $this->renderContent($compiled, $data);
            
            // Validate output
            $this->validateOutput($rendered);
            
            return new RenderedContent($rendered, $this->generateMetadata($template));
            
        } catch (\Exception $e) {
            $this->handleRenderFailure($e, $template);
            throw $e;
        }
    }

    public function updateTemplate(string $template, TemplateContent $content, SecurityContext $context): void
    {
        DB::beginTransaction();

        try {
            // Validate access
            $this->security->validateTemplateAccess($context);
            
            // Validate template
            $this->validateTemplateContent($content);
            
            // Process update
            $this->processTemplateUpdate($template, $content);
            
            // Clear cache
            $this->invalidateTemplateCache($template);
            
            DB::commit();
            
            // Log update
            $this->audit->logTemplateUpdate($template);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUpdateFailure($e, $template);
            throw $e;
        }
    }

    private function loadTemplate(string $template): TemplateContent
    {
        // Try cache first
        $cached = $this->cache->get($this->getTemplateCacheKey($template));
        if ($cached) {
            return $cached;
        }

        // Load from storage
        $content = $this->loadTemplateFromStorage($template);
        
        // Cache template
        $this->cacheTemplate($template, $content);
        
        return $content;
    }

    private function compileTemplate(TemplateContent $template, array $data): CompiledTemplate
    {
        $startTime = microtime(true);
        
        try {
            // Set compilation timeout
            set_time_limit(self::COMPILATION_TIMEOUT);
            
            $compiled = $this->compiler->compile(
                $template->getContent(),
                $this->prepareCompilationContext($data)
            );
            
            return new CompiledTemplate($compiled);
            
        } catch (\Exception $e) {
            throw new TemplateCompilationException(
                "Template compilation failed: {$e->getMessage()}",
                previous: $e
            );
        } finally {
            // Reset timeout
            set_time_limit(0);
            
            // Log compilation time
            $this->audit->logCompilationTime(
                microtime(true) - $startTime
            );
        }
    }

    private function renderContent(CompiledTemplate $template, array $data): string
    {
        return $template->render($data);
    }

    private function validateTemplateData(array $data, TemplateContent $template): void
    {
        if (!$this->validator->validateTemplateData($data, $template)) {
            throw new TemplateValidationException('Invalid template data');
        }
    }

    private function validateOutput(string $output): void
    {
        if (!$this->validator->validateTemplateOutput($output)) {
            throw new TemplateValidationException('Invalid template output');
        }
    }

    private function validateTemplateContent(TemplateContent $content): void
    {
        if (!$this->validator->validateTemplate($content)) {
            throw new TemplateValidationException('Invalid template content');
        }
    }

    private function processTemplateUpdate(string $template, TemplateContent $content): void
    {
        // Compile to verify syntax
        $this->compileTemplate($content, []);
        
        // Store template
        $this->storeTemplate($template, $content);
    }

    private function invalidateTemplateCache(string $template): void
    {
        $this->cache->delete($this->getTemplateCacheKey($template));
    }

    private function getTemplateCacheKey(string $template): string
    {
        return "template:{$template}";
    }

    private function cacheTemplate(string $template, TemplateContent $content): void
    {
        $this->cache->set(
            $this->getTemplateCacheKey($template),
            $content,
            self::TEMPLATE_CACHE_TTL
        );
    }

    private function generateMetadata(string $template): array
    {
        return [
            'template' => $template,
            'rendered_at' => now(),
            'cache_key' => $this->getTemplateCacheKey($template)
        ];
    }

    private function handleRenderFailure(\Exception $e, string $template): void
    {
        $this->audit->logTemplateFailure($e, [
            'template' => $template,
            'timestamp' => now()
        ]);
    }
}
