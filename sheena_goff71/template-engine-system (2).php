<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{DB, Cache, View};
use App\Core\Security\SecurityContext;
use App\Core\Services\{ValidationService, CacheService, AuditService};
use App\Core\Exceptions\{TemplateException, RenderException, SecurityException};

class TemplateEngine implements TemplateEngineInterface 
{
    private ValidationService $validator;
    private CacheService $cache;
    private AuditService $audit;
    private array $config;
    private array $securityRules;

    public function __construct(
        ValidationService $validator,
        CacheService $cache,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->config = config('template');
        $this->securityRules = config('template.security');
    }

    public function render(string $template, array $data, SecurityContext $context): string 
    {
        try {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);

            // Check cache
            $cacheKey = $this->getCacheKey($template, $data);
            if ($cached = $this->getFromCache($cacheKey)) {
                return $cached;
            }

            // Process template
            $processed = DB::transaction(function() use ($template, $data, $context) {
                // Compile template
                $compiled = $this->compileTemplate($template);
                
                // Apply security measures
                $secured = $this->applySecurityMeasures($compiled);
                
                // Render with data
                $rendered = $this->renderTemplate($secured, $data);
                
                // Post-process output
                return $this->postProcess($rendered);
            });

            // Cache result
            $this->cacheResult($cacheKey, $processed);

            // Audit render operation
            $this->audit->logTemplateRender($template, $context);

            return $processed;

        } catch (\Exception $e) {
            $this->handleRenderFailure($e, $template, $context);
            throw new RenderException('Template rendering failed: ' . $e->getMessage());
        }
    }

    public function compile(string $template, SecurityContext $context): CompiledTemplate 
    {
        return DB::transaction(function() use ($template, $context) {
            try {
                // Validate source
                $this->validateTemplateSource($template);

                // Parse template
                $ast = $this->parseTemplate($template);

                // Optimize structure
                $optimized = $this->optimizeAst($ast);

                // Generate compiled code
                $compiled = $this->generateCode($optimized);

                // Validate compiled output
                $this->validateCompiled($compiled);

                // Create compiled template
                $compiledTemplate = new CompiledTemplate(
                    $compiled,
                    $this->extractMetadata($ast),
                    $this->generateHash($compiled)
                );

                // Cache compiled version
                $this->cacheCompiled($compiledTemplate);

                // Log compilation
                $this->audit->logTemplateCompilation($template, $context);

                return $compiledTemplate;

            } catch (\Exception $e) {
                $this->handleCompilationFailure($e, $template, $context);
                throw new TemplateException('Template compilation failed: ' . $e->getMessage());
            }
        });
    }

    private function validateTemplate(string $template): void 
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateException('Invalid template structure');
        }
    }

    private function validateData(array $data): void 
    {
        if (!$this->validator->validateTemplateData($data)) {
            throw new TemplateException('Invalid template data');
        }
    }

    private function parseTemplate(string $template): array 
    {
        $parser = new TemplateParser($this->config['parser_rules']);
        return $parser->parse($template);
    }

    private function optimizeAst(array $ast): array 
    {
        $optimizer = new AstOptimizer($this->config['optimization_rules']);
        return $optimizer->optimize($ast);
    }

    private function generateCode(array $ast): string 
    {
        $generator = new CodeGenerator($this->config['generator_rules']);
        return $generator->generate($ast);
    }

    private function applySecurityMeasures(string $template): string 
    {
        // Apply XSS protection
        $template = $this->escapeHtml($template);

        // Apply CSP directives
        $template = $this->applyCspDirectives($template);

        // Remove dangerous patterns
        $template = $this->removeDangerousPatterns($template);

        return $template;
    }

    private function renderTemplate(string $template, array $data): string 
    {
        $renderer = new TemplateRenderer(
            $this->config['renderer_options'],
            $this->securityRules
        );
        
        return $renderer->render($template, $this->sanitizeData($data));
    }

    private function postProcess(string $rendered): string 
    {
        // Optimize output
        $rendered = $this->optimizeOutput($rendered);

        // Validate final output
        if (!$this->validateOutput($rendered)) {
            throw new RenderException('Invalid template output');
        }

        return $rendered;
    }

    private function sanitizeData(array $data): array 
    {
        $sanitizer = new DataSanitizer($this->securityRules);
        return $sanitizer->sanitize($data);
    }

    private function optimizeOutput(string $output): string 
    {
        $optimizer = new OutputOptimizer($this->config['optimization_rules']);
        return $optimizer->optimize($output);
    }

    private function validateOutput(string $output): bool 
    {
        return $this->validator->validateOutput($output, $this->config['output_rules']);
    }

    private function getCacheKey(string $template, array $data): string 
    {
        return 'template:' . md5($template . serialize($data));
    }

    private function getFromCache(string $key): ?string 
    {
        return $this->cache->get($key);
    }

    private function cacheResult(string $key, string $result): void 
    {
        $this->cache->put($key, $result, $this->config['cache_ttl']);
    }

    private function handleRenderFailure(\Exception $e, string $template, SecurityContext $context): void 
    {
        $this->audit->logRenderFailure($template, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleCompilationFailure(\Exception $e, string $template, SecurityContext $context): void 
    {
        $this->audit->logCompilationFailure($template, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function escapeHtml(string $content): string 
    {
        return htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function applyCspDirectives(string $template): string 
    {
        foreach ($this->securityRules['csp_directives'] as $directive) {
            $template = $this->applyCspDirective($template, $directive);
        }
        return $template;
    }

    private function removeDangerousPatterns(string $template): string 
    {
        foreach ($this->securityRules['dangerous_patterns'] as $pattern) {
            $template = preg_replace($pattern['regex'], $pattern['replacement'], $template);
        }
        return $template;
    }
}
