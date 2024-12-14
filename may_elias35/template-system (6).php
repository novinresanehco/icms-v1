<?php

namespace App\Core\Template;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\{CacheManager, ValidationService};
use Illuminate\Support\Facades\{DB, View};
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateManagerInterface
{
    private CoreSecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $config;
    private array $compiledTemplates = [];

    public function __construct(
        CoreSecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeSecureOperation(
            function() use ($template, $data) {
                $this->validateTemplate($template);
                $this->validateTemplateData($data);
                
                $cacheKey = $this->getTemplateCacheKey($template, $data);
                
                return $this->cache->remember($cacheKey, function() use ($template, $data) {
                    $compiled = $this->compileTemplate($template);
                    return $this->renderCompiled($compiled, $this->sanitizeData($data));
                });
            },
            ['action' => 'render_template', 'template' => $template]
        );
    }

    public function registerExtension(string $name, callable $extension): void
    {
        $this->security->executeSecureOperation(
            function() use ($name, $extension) {
                $this->validateExtension($name, $extension);
                $this->extensions[$name] = $extension;
                $this->clearCompiledTemplates();
            },
            ['action' => 'register_extension', 'name' => $name]
        );
    }

    public function compileTemplate(string $template): CompiledTemplate
    {
        if (isset($this->compiledTemplates[$template])) {
            return $this->compiledTemplates[$template];
        }

        $source = $this->loadTemplate($template);
        $ast = $this->parseTemplate($source);
        $optimized = $this->optimizeAst($ast);
        
        $compiled = new CompiledTemplate(
            $template,
            $this->generateCode($optimized),
            $this->extractDependencies($ast)
        );

        $this->compiledTemplates[$template] = $compiled;
        return $compiled;
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplatePath($template)) {
            throw new SecurityException('Invalid template path');
        }

        if (!$this->templateExists($template)) {
            throw new TemplateException('Template not found: ' . $template);
        }
    }

    protected function validateTemplateData(array $data): void
    {
        if (!$this->validator->validateTemplateData($data)) {
            throw new SecurityException('Invalid template data');
        }
    }

    protected function validateExtension(string $name, callable $extension): void
    {
        if (!$this->validator->validateExtensionName($name)) {
            throw new SecurityException('Invalid extension name');
        }
    }

    protected function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (!$this->validator->validateDataKey($key)) {
                continue;
            }
            $sanitized[$key] = $this->sanitizeValue($value);
        }
        return $sanitized;
    }

    protected function sanitizeValue($value)
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (is_array($value)) {
            return array_map([$this, 'sanitizeValue'], $value);
        }
        return $value;
    }

    protected function loadTemplate(string $template): string
    {
        $path = $this->resolveTemplatePath($template);
        if (!is_readable($path)) {
            throw new TemplateException('Template not readable: ' . $template);
        }
        return file_get_contents($path);
    }

    protected function parseTemplate(string $source): array
    {
        $parser = new TemplateParser($this->config['syntax']);
        return $parser->parse($source);
    }

    protected function optimizeAst(array $ast): array
    {
        $optimizer = new TemplateOptimizer($this->config['optimization']);
        return $optimizer->optimize($ast);
    }

    protected function generateCode(array $ast): string
    {
        $generator = new CodeGenerator($this->config['generation']);
        return $generator->generate($ast);
    }

    protected function renderCompiled(CompiledTemplate $compiled, array $data): string
    {
        try {
            return View::make(
                $compiled->getViewName(),
                $this->prepareViewData($data)
            )->render();
        } catch (\Throwable $e) {
            throw new TemplateException('Template rendering failed: ' . $e->getMessage());
        }
    }

    protected function getTemplateCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template:%s:%s',
            $template,
            md5(serialize($data))
        );
    }

    protected function templateExists(string $template): bool
    {
        return file_exists($this->resolveTemplatePath($template));
    }

    protected function resolveTemplatePath(string $template): string
    {
        return $this->config['template_path'] . '/' . $template;
    }

    protected function extractDependencies(array $ast): array
    {
        $extractor = new DependencyExtractor();
        return $extractor->extract($ast);
    }

    protected function prepareViewData(array $data): array
    {
        return array_merge(
            $data,
            $this->getGlobalData(),
            ['extensions' => $this->extensions]
        );
    }

    protected function getGlobalData(): array
    {
        return $this->config['global_data'] ?? [];
    }

    protected function clearCompiledTemplates(): void
    {
        $this->compiledTemplates = [];
        $this->cache->tags(['templates'])->flush();
    }
}
