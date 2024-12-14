<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Services\{CacheManager, ValidationService};
use App\Core\Interfaces\{TemplateManagerInterface, RenderEngineInterface};
use Illuminate\Support\Facades\{View, Cache};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private RenderEngineInterface $renderEngine;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        RenderEngineInterface $renderEngine
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->renderEngine = $renderEngine;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleRender($template, $data),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    public function compile(string $template): CompiledTemplate
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleCompilation($template),
            ['action' => 'compile_template', 'template' => $template]
        );
    }

    public function registerComponent(string $name, array $component): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->handleComponentRegistration($name, $component),
            ['action' => 'register_component', 'name' => $name]
        );
    }

    public function getCachedTemplate(string $template, array $data = []): string
    {
        $cacheKey = $this->generateCacheKey($template, $data);
        
        return $this->cache->remember(
            $cacheKey,
            fn() => $this->render($template, $data),
            $this->getCacheDuration($template)
        );
    }

    private function handleRender(string $template, array $data): string
    {
        // Validate template exists
        if (!$this->templateExists($template)) {
            throw new TemplateNotFoundException("Template {$template} not found");
        }

        // Validate template data
        $validatedData = $this->validateTemplateData($data);

        // Apply security filters to data
        $secureData = $this->sanitizeData($validatedData);

        // Render template with secure data
        try {
            $rendered = $this->renderEngine->render($template, $secureData);
            return $this->postProcessOutput($rendered);
        } catch (\Exception $e) {
            throw new TemplateRenderException(
                "Failed to render template: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    private function handleCompilation(string $template): CompiledTemplate
    {
        // Validate template syntax
        $this->validateTemplateSyntax($template);

        // Compile template
        try {
            $compiled = $this->renderEngine->compile($template);
            
            // Validate compiled output
            $this->validateCompiledOutput($compiled);
            
            return new CompiledTemplate($compiled);
        } catch (\Exception $e) {
            throw new TemplateCompilationException(
                "Failed to compile template: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    private function handleComponentRegistration(string $name, array $component): void
    {
        // Validate component structure
        $validatedComponent = $this->validateComponent($name, $component);

        // Register with render engine
        $this->renderEngine->registerComponent($name, $validatedComponent);

        // Clear component cache
        $this->cache->tags(['components'])->forget($name);
    }

    private function validateTemplateSyntax(string $template): void
    {
        $result = $this->renderEngine->validate($template);
        
        if (!$result->isValid()) {
            throw new TemplateSyntaxException(
                "Invalid template syntax: " . implode(', ', $result->getErrors())
            );
        }
    }

    private function validateTemplateData(array $data): array
    {
        return $this->validator->validate($data, [
            '*' => 'required|safe_content',
            '*.scripts' => 'prohibited',
            '*.styles' => 'css_safe'
        ]);
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    private function sanitizeString(string $value): string
    {
        // Apply multiple levels of sanitization
        $sanitized = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sanitized = preg_replace('/javascript:/i', '', $sanitized);
        return $sanitized;
    }

    private function postProcessOutput(string $output): string
    {
        // Apply security headers
        $output = $this->appendSecurityHeaders($output);
        
        // Validate final output
        if (!$this->validateOutput($output)) {
            throw new TemplateSecurityException("Template output failed security validation");
        }
        
        return $output;
    }

    private function appendSecurityHeaders(string $output): string
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff'
        ];

        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "<meta http-equiv=\"{$key}\" content=\"{$value}\">\n";
        }

        return preg_replace('/<head>/i', "<head>\n{$headerString}", $output);
    }

    private function validateOutput(string $output): bool
    {
        // Check for potential XSS vectors
        if (preg_match('/<script\b[^>]*>/', $output)) {
            return false;
        }

        // Validate HTML structure
        return $this->validator->validateHtml($output);
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }

    private function getCacheDuration(string $template): int
    {
        // Different cache durations based on template type
        return match ($this->getTemplateType($template)) {
            'static' => 86400, // 24 hours
            'dynamic' => 3600, // 1 hour
            default => 300     // 5 minutes
        };
    }

    private function getTemplateType(string $template): string
    {
        // Determine template type based on content and usage
        return $this->renderEngine->analyzeTemplate($template)->getType();
    }
}
