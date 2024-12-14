<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{View, Cache, File};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, HtmlSanitizer};
use App\Core\Interfaces\TemplateManagerInterface;
use App\Core\Exceptions\{TemplateException, ValidationException};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private HtmlSanitizer $sanitizer;
    private array $config;
    private array $compiledTemplates = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        HtmlSanitizer $sanitizer,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->sanitizer = $sanitizer;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processTemplate($template, $data),
            new SecurityContext('template.render', ['template' => $template])
        );
    }

    public function compile(string $template): string
    {
        return $this->security->executeSecureOperation(
            fn() => $this->compileTemplate($template),
            new SecurityContext('template.compile', ['template' => $template])
        );
    }

    public function registerComponent(string $name, string $template): void
    {
        $this->security->executeSecureOperation(
            fn() => $this->processComponentRegistration($name, $template),
            new SecurityContext('template.register', ['component' => $name])
        );
    }

    protected function processTemplate(string $template, array $data): string
    {
        try {
            $this->validateTemplate($template);
            $this->validateTemplateData($data);

            $cacheKey = $this->generateTemplateCacheKey($template, $data);
            
            if ($this->hasCompiledTemplate($cacheKey)) {
                return $this->getCompiledTemplate($cacheKey);
            }

            $compiledTemplate = $this->compileAndRender($template, $data);
            $sanitizedOutput = $this->sanitizeOutput($compiledTemplate);
            
            $this->cacheCompiledTemplate($cacheKey, $sanitizedOutput);
            
            return $sanitizedOutput;

        } catch (\Exception $e) {
            $this->handleTemplateFailure($template, $e);
            throw new TemplateException('Template processing failed: ' . $e->getMessage());
        }
    }

    protected function compileTemplate(string $template): string
    {
        try {
            $this->validateTemplate($template);
            
            $compiled = View::make($template)->render();
            $optimized = $this->optimizeCompiledTemplate($compiled);
            
            return $optimized;

        } catch (\Exception $e) {
            $this->handleCompilationFailure($template, $e);
            throw new TemplateException('Template compilation failed: ' . $e->getMessage());
        }
    }

    protected function processComponentRegistration(string $name, string $template): void
    {
        try {
            $this->validateComponentName($name);
            $this->validateComponentTemplate($template);
            
            View::component($name, $template);
            $this->clearComponentCache($name);

        } catch (\Exception $e) {
            $this->handleRegistrationFailure($name, $e);
            throw new TemplateException('Component registration failed: ' . $e->getMessage());
        }
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplatePath($template)) {
            throw new ValidationException('Invalid template path');
        }

        if (!$this->validator->validateTemplateContent(File::get($template))) {
            throw new ValidationException('Invalid template content');
        }
    }

    protected function validateTemplateData(array $data): void
    {
        if (!$this->validator->validateTemplateData($data)) {
            throw new ValidationException('Invalid template data');
        }
    }

    protected function validateComponentName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            throw new ValidationException('Invalid component name');
        }
    }

    protected function validateComponentTemplate(string $template): void
    {
        if (!$this->validator->validateComponentSyntax($template)) {
            throw new ValidationException('Invalid component syntax');
        }
    }

    protected function generateTemplateCacheKey(string $template, array $data): string
    {
        return hash('xxh3', serialize([
            'template' => $template,
            'data' => $data,
            'version' => $this->config['template_version']
        ]));
    }

    protected function hasCompiledTemplate(string $key): bool
    {
        return isset($this->compiledTemplates[$key]) || Cache::has($key);
    }

    protected function getCompiledTemplate(string $key): string
    {
        if (isset($this->compiledTemplates[$key])) {
            return $this->compiledTemplates[$key];
        }

        $compiled = Cache::get($key);
        $this->compiledTemplates[$key] = $compiled;
        return $compiled;
    }

    protected function cacheCompiledTemplate(string $key, string $compiled): void
    {
        $this->compiledTemplates[$key] = $compiled;
        Cache::put($key, $compiled, $this->config['template_cache_ttl']);
    }

    protected function compileAndRender(string $template, array $data): string
    {
        $view = View::make($template, $this->prepareTemplateData($data));
        return $view->render();
    }

    protected function prepareTemplateData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->sanitizer->sanitize($value);
            }
            return $value;
        }, $data);
    }

    protected function sanitizeOutput(string $output): string
    {
        return $this->sanitizer->sanitizeHtml($output, [
            'allowed_tags' => $this->config['allowed_html_tags'],
            'allowed_attrs' => $this->config['allowed_html_attributes'],
            'allowed_protocols' => $this->config['allowed_protocols']
        ]);
    }

    protected function optimizeCompiledTemplate(string $compiled): string
    {
        // Remove unnecessary whitespace
        $compiled = preg_replace('/\s+/', ' ', $compiled);
        
        // Remove HTML comments
        $compiled = preg_replace('/<!--(?!\/?).*?-->/', '', $compiled);
        
        // Minify inline CSS
        $compiled = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) {
            return '<style>' . $this->minifyCss($matches[1]) . '</style>';
        }, $compiled);
        
        // Minify inline JavaScript
        $compiled = preg_replace_callback('/<script[^>]*>(.*?)<\/script>/is', function($matches) {
            return '<script>' . $this->minifyJs($matches[1]) . '</script>';
        }, $compiled);
        
        return $compiled;
    }

    protected function clearComponentCache(string $name): void
    {
        Cache::tags(['components', $name])->flush();
    }

    protected function handleTemplateFailure(string $template, \Exception $e): void
    {
        Log::error('Template processing failed', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleCompilationFailure(string $template, \Exception $e): void
    {
        Log::error('Template compilation failed', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
