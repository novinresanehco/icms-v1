<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Audit\AuditLoggerInterface;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private AuditLoggerInterface $audit;
    private TemplateRepository $repository;
    private ThemeManager $themeManager;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        AuditLoggerInterface $audit,
        TemplateRepository $repository,
        ThemeManager $themeManager
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->repository = $repository;
        $this->themeManager = $themeManager;
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation($template, $data),
            function() use ($template, $data, $options) {
                // Get template from cache or compile
                $compiledTemplate = $this->getCompiledTemplate($template);
                
                // Validate and sanitize data
                $safeData = $this->sanitizeTemplateData($data);
                
                // Apply theme overrides
                $themedTemplate = $this->applyTheme($compiledTemplate, $options['theme'] ?? null);
                
                // Render with security context
                return $this->renderSecure($themedTemplate, $safeData);
            }
        );
    }

    protected function getCompiledTemplate(string $template): CompiledTemplate
    {
        $cacheKey = "template:compiled:{$template}";
        
        return $this->cache->remember($cacheKey, function() use ($template) {
            // Load template
            $source = $this->repository->getTemplate($template);
            
            // Validate template security
            $this->validateTemplateSource($source);
            
            // Compile template
            return $this->compileTemplate($source);
        });
    }

    protected function compileTemplate(string $source): CompiledTemplate
    {
        $compiler = new TemplateCompiler([
            'security_level' => 'high',
            'cache_enabled' => true,
            'strict_variables' => true
        ]);

        // Register security filters
        $this->registerSecurityFilters($compiler);
        
        // Register theme functions
        $this->registerThemeFunctions($compiler);
        
        // Compile with full security checks
        return $compiler->compile($source);
    }

    protected function sanitizeTemplateData(array $data): array
    {
        $sanitizer = new TemplateSanitizer();
        
        // Apply XSS protection
        $data = $sanitizer->escapeAll($data);
        
        // Apply type constraints
        $data = $sanitizer->enforceTypes($data);
        
        return $data;
    }

    protected function validateTemplateSource(string $source): void
    {
        $validator = new TemplateSecurityValidator();
        
        if (!$validator->validate($source)) {
            throw new TemplateSecurityException(
                'Template contains potentially unsafe content'
            );
        }
    }

    protected function renderSecure(CompiledTemplate $template, array $data): string
    {
        try {
            // Create isolated render context
            $context = new RenderContext($data);
            
            // Apply security restrictions
            $context->setSecurityPolicy($this->getSecurityPolicy());
            
            // Enable output buffering
            ob_start();
            
            // Render with error handling
            $result = $template->render($context);
            
            // Verify output safety
            $this->validateOutput($result);
            
            return $result;
            
        } catch (\Exception $e) {
            ob_end_clean();
            $this->handleRenderError($e, $template);
            throw $e;
        }
    }

    protected function getSecurityPolicy(): TemplateSecurityPolicy
    {
        return new TemplateSecurityPolicy([
            'allowed_tags' => $this->config->get('templates.allowed_tags'),
            'allowed_functions' => $this->config->get('templates.allowed_functions'),
            'allowed_filters' => $this->config->get('templates.allowed_filters')
        ]);
    }

    protected function validateOutput(string $output): void
    {
        $validator = new OutputValidator();
        
        if (!$validator->validate($output)) {
            throw new TemplateOutputException(
                'Template output failed security validation'
            );
        }
    }

    protected function applyTheme(CompiledTemplate $template, ?string $theme): CompiledTemplate
    {
        if (!$theme) {
            return $template;
        }

        return $this->themeManager->apply($template, $theme);
    }

    protected function registerSecurityFilters(TemplateCompiler $compiler): void
    {
        $compiler->addFilter('escape', [$this, 'escapeHtml']);
        $compiler->addFilter('escape_js', [$this, 'escapeJs']);
        $compiler->addFilter('escape_css', [$this, 'escapeCss']);
        $compiler->addFilter('escape_url', [$this, 'escapeUrl']);
    }

    protected function registerThemeFunctions(TemplateCompiler $compiler): void
    {
        $compiler->addFunction('theme_asset', [$this->themeManager, 'getAsset']);
        $compiler->addFunction('theme_config', [$this->themeManager, 'getConfig']);
        $compiler->addFunction('theme_partial', [$this->themeManager, 'renderPartial']);
    }
}
