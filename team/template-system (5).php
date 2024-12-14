<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\{DB, View};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManager $cache;
    private CompilerInterface $compiler;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManager $cache,
        CompilerInterface $compiler,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        return $this->security->executeCriticalOperation(
            function() use ($template, $data, $options) {
                $this->validateTemplate($template);
                $this->validateData($data);

                $cacheKey = $this->generateCacheKey($template, $data);

                return $this->cache->remember($cacheKey, function() use ($template, $data, $options) {
                    return $this->renderTemplate($template, $data, $options);
                }, $this->getCacheTtl($options));
            },
            new SecurityContext('template.render')
        );
    }

    public function compile(string $source, array $options = []): CompiledTemplate
    {
        return $this->security->executeCriticalOperation(
            function() use ($source, $options) {
                $this->validateSource($source);
                
                DB::beginTransaction();
                try {
                    $compiled = $this->compileTemplate($source, $options);
                    $this->validateCompiled($compiled);
                    
                    DB::commit();
                    return $compiled;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            },
            new SecurityContext('template.compile')
        );
    }

    public function extend(string $name, callable $extension): void
    {
        $this->security->executeCriticalOperation(
            function() use ($name, $extension) {
                $this->validateExtension($name, $extension);
                $this->registerExtension($name, $extension);
                $this->clearExtensionCache($name);
            },
            new SecurityContext('template.extend')
        );
    }

    protected function renderTemplate(string $template, array $data, array $options): string
    {
        $compiled = $this->resolveTemplate($template);
        $context = $this->createContext($data, $options);

        return $this->compiler->render($compiled, $context);
    }

    protected function resolveTemplate(string $template): CompiledTemplate
    {
        $source = $this->loadTemplate($template);
        return $this->compile($source);
    }

    protected function compileTemplate(string $source, array $options): CompiledTemplate
    {
        $ast = $this->compiler->parse($source);
        $this->validateAst($ast);

        $optimized = $this->compiler->optimize($ast, $options);
        $compiled = $this->compiler->compile($optimized);

        return new CompiledTemplate($compiled, [
            'hash' => $this->calculateHash($source),
            'dependencies' => $this->extractDependencies($ast),
            'metadata' => $this->extractMetadata($ast)
        ]);
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateValidationException('Invalid template');
        }

        if (!$this->templateExists($template)) {
            throw new TemplateNotFoundException("Template not found: $template");
        }
    }

    protected function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->validator->validateData($key, $value)) {
                throw new TemplateDataException("Invalid data for key: $key");
            }
        }
    }

    protected function validateSource(string $source): void
    {
        if (!$this->validator->validateSource($source)) {
            throw new TemplateSourceException('Invalid template source');
        }

        $this->validateSecurity($source);
    }

    protected function validateSecurity(string $source): void
    {
        $violations = $this->security->analyzeTemplate($source);

        if (!empty($violations)) {
            throw new TemplateSecurityException(
                'Security violations detected: ' . implode(', ', $violations)
            );
        }
    }

    protected function validateCompiled(CompiledTemplate $compiled): void
    {
        if (!$this->validator->validateCompiled($compiled)) {
            throw new TemplateCompilationException('Invalid compiled template');
        }
    }

    protected function validateAst(array $ast): void
    {
        $violations = $this->security->analyzeAst($ast);

        if (!empty($violations)) {
            throw new TemplateSecurityException(
                'AST security violations: ' . implode(', ', $violations)
            );
        }
    }

    protected function validateExtension(string $name, callable $extension): void
    {
        if (!$this->validator->validateExtension($name, $extension)) {
            throw new TemplateExtensionException('Invalid template extension');
        }
    }

    protected function generateCacheKey(string $template, array $data): string
    {
        return 'template:' . hash('sha256', serialize([
            'template' => $template,
            'data' => $data,
            'version' => $this->config['version']
        ]));
    }

    protected function getCacheTtl(array $options): int
    {
        return $options['cache_ttl'] 
            ?? $this->config['cache_ttl'] 
            ?? 3600;
    }

    protected function createContext(array $data, array $options): TemplateContext
    {
        return new TemplateContext(
            $data,
            $this->security->getCurrentUser(),
            $options
        );
    }
}
