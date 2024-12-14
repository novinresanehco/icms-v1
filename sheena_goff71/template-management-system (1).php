<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{View, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{TemplateManagerInterface, ViewInterface};
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        TemplateCompiler $compiler, 
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTemplateRender($template, $data),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    public function compile(string $template): CompiledTemplate
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTemplateCompilation($template),
            ['action' => 'compile_template', 'template' => $template]
        );
    }

    protected function executeTemplateRender(string $template, array $data): string
    {
        $this->validateTemplate($template);
        $this->validateTemplateData($data);

        try {
            $compiledTemplate = $this->getCompiledTemplate($template);
            $processedData = $this->processTemplateData($data);
            
            return $this->renderWithSecurity(
                $compiledTemplate,
                $processedData
            );
            
        } catch (\Exception $e) {
            $this->handleRenderFailure($template, $e);
            throw new TemplateException('Template render failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function executeTemplateCompilation(string $template): CompiledTemplate
    {
        $this->validateTemplate($template);

        try {
            $source = $this->repository->getTemplateSource($template);
            $this->validateTemplateSource($source);
            
            $compiled = $this->compiler->compile($source);
            $this->validateCompiledTemplate($compiled);
            
            $this->cacheCompiledTemplate($template, $compiled);
            
            return $compiled;
            
        } catch (\Exception $e) {
            $this->handleCompilationFailure($template, $e);
            throw new TemplateException('Template compilation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function getCompiledTemplate(string $template): CompiledTemplate
    {
        $cacheKey = "template:compiled:{$template}";
        
        return Cache::remember(
            $cacheKey,
            $this->config['cache_ttl'],
            fn() => $this->executeTemplateCompilation($template)
        );
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplateName($template)) {
            throw new TemplateException('Invalid template name');
        }

        if (!$this->repository->exists($template)) {
            throw new TemplateException('Template not found');
        }
    }

    protected function validateTemplateSource(string $source): void
    {
        if (!$this->validator->validateTemplateSource($source)) {
            throw new TemplateException('Invalid template source');
        }

        if ($this->containsMaliciousCode($source)) {
            throw new SecurityException('Malicious template code detected');
        }
    }

    protected function validateTemplateData(array $data): void
    {
        if (!$this->validator->validateTemplateData($data)) {
            throw new TemplateException('Invalid template data');
        }
    }

    protected function validateCompiledTemplate(CompiledTemplate $compiled): void
    {
        if (!$this->validator->validateCompiledTemplate($compiled)) {
            throw new TemplateException('Invalid compiled template');
        }
    }

    protected function processTemplateData(array $data): array
    {
        $processed = [];
        
        foreach ($data as $key => $value) {
            $processed[$key] = $this->sanitizeTemplateValue($value);
        }
        
        return array_merge(
            $processed,
            $this->getDefaultTemplateData()
        );
    }

    protected function renderWithSecurity(CompiledTemplate $template, array $data): string
    {
        $sandboxConfig = [
            'disable_functions' => $this->config['disabled_functions'],
            'allowed_classes' => $this->config['allowed_classes'],
            'memory_limit' => $this->config['render_memory_limit']
        ];

        return $this->security->executeCriticalOperation(
            fn() => $template->render($data),
            ['context' => 'template_render', 'sandbox' => $sandboxConfig]
        );
    }

    protected function containsMaliciousCode(string $source): bool
    {
        foreach ($this->config['dangerous_patterns'] as $pattern) {
            if (preg_match($pattern, $source)) {
                return true;
            }
        }
        return false;
    }

    protected function sanitizeTemplateValue($value)
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        
        if (is_array($value)) {
            return array_map([$this, 'sanitizeTemplateValue'], $value);
        }
        
        return $value;
    }

    protected function getDefaultTemplateData(): array
    {
        return [
            'csrf_token' => csrf_token(),
            'current_user' => auth()->user(),
            'current_time' => now(),
            'app_version' => $this->config['app_version']
        ];
    }

    protected function cacheCompiledTemplate(string $template, CompiledTemplate $compiled): void
    {
        Cache::tags(['templates'])
            ->put(
                "template:compiled:{$template}",
                $compiled,
                $this->config['cache_ttl']
            );
    }

    protected function handleRenderFailure(string $template, \Exception $e): void
    {
        $this->security->logSecurityEvent('template_render_failure', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleCompilationFailure(string $template, \Exception $e): void
    {
        $this->security->logSecurityEvent('template_compilation_failure', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        Cache::tags(['templates'])->forget("template:compiled:{$template}");
    }
}
