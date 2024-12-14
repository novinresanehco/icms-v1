<?php

namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface
{
    private TemplateRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private CompilerService $compiler;
    private array $config;

    public function __construct(
        TemplateRepository $repository,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics,
        CompilerService $compiler,
        array $config
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->compiler = $compiler;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        $startTime = microtime(true);
        
        try {
            $this->validateRenderAccess($template);
            $this->validateTemplateData($data);
            
            $compiled = $this->getCompiledTemplate($template);
            
            $this->security->validateTemplateExecution($compiled);
            
            $result = $this->executeTemplate($compiled, $this->sanitizeData($data));
            
            $this->recordMetrics('render', $startTime);
            return $result;
            
        } catch (\Exception $e) {
            $this->handleRenderFailure($e, $template, $data);
            throw $e;
        }
    }

    public function compile(string $template): CompiledTemplate
    {
        $startTime = microtime(true);
        
        try {
            $this->validateCompileAccess();
            $this->validateTemplate($template);
            
            $compiled = $this->compiler->compile(
                $template,
                $this->config['compiler_options']
            );
            
            $this->security->validateCompiledTemplate($compiled);
            
            $this->cacheCompiledTemplate($template, $compiled);
            $this->recordMetrics('compile', $startTime);
            
            return $compiled;
            
        } catch (\Exception $e) {
            $this->handleCompileFailure($e, $template);
            throw $e;
        }
    }

    public function registerCustomTag(string $name, callable $handler): void
    {
        $this->validateTagAccess();
        $this->validateTagName($name);
        $this->validateTagHandler($handler);
        
        $this->compiler->registerTag($name, $handler);
        $this->invalidateCompiledCache();
    }

    private function validateRenderAccess(string $template): void
    {
        $this->security->enforcePermission(
            'template.render',
            ['template' => $template]
        );
    }

    private function validateCompileAccess(): void
    {
        $this->security->enforcePermission('template.compile');
    }

    private function validateTagAccess(): void
    {
        $this->security->enforcePermission('template.register_tag');
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new ValidationException('Invalid template structure');
        }
    }

    private function validateTemplateData(array $data): void
    {
        if (!$this->validator->validateData($data)) {
            throw new ValidationException('Invalid template data');
        }
    }

    private function validateTagName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new ValidationException('Invalid tag name');
        }
    }

    private function validateTagHandler(callable $handler): void
    {
        if (!$this->validator->validateHandler($handler)) {
            throw new ValidationException('Invalid tag handler');
        }
    }

    private function getCompiledTemplate(string $template): CompiledTemplate
    {
        return $this->cache->remember(
            $this->getTemplateCacheKey($template),
            fn() => $this->compile($template)
        );
    }

    private function executeTemplate(CompiledTemplate $compiled, array $data): string
    {
        $sandbox = $this->createSecureSandbox();
        return $sandbox->execute($compiled, $data);
    }

    private function sanitizeData(array $data): array
    {
        return array_map(
            fn($value) => $this->security->sanitize($value),
            $data
        );
    }

    private function createSecureSandbox(): TemplateSandbox
    {
        return new TemplateSandbox(
            $this->config['sandbox_options'],
            $this->security
        );
    }

    private function cacheCompiledTemplate(
        string $template,
        CompiledTemplate $compiled
    ): void {
        $this->cache->put(
            $this->getTemplateCacheKey($template),
            $compiled,
            $this->config['cache_ttl']
        );
    }

    private function getTemplateCacheKey(string $template): string
    {
        return sprintf(
            'template:%s:%s',
            md5($template),
            $this->config['version']
        );
    }

    private function invalidateCompiledCache(): void
    {
        $this->cache->tags(['templates'])->flush();
    }

    private function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record('template_operation', [
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function handleRenderFailure(
        \Exception $e,
        string $template,
        array $data
    ): void {
        $this->metrics->increment('template_error', [
            'operation' => 'render',
            'error' => get_class($e),
            'template' => $template
        ]);
    }

    private function handleCompileFailure(
        \Exception $e,
        string $template
    ): void {
        $this->metrics->increment('template_error', [
            'operation' => 'compile',
            'error' => get_class($e),
            'template' => $template
        ]);
    }
}
