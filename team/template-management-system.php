<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private CoreProtectionSystem $protection;
    private CompilerService $compiler;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function render(string $template, array $data, SecurityContext $context): string
    {
        return $this->protection->executeProtectedOperation(
            function() use ($template, $data, $context) {
                $validated = $this->validateTemplate($template);
                $safeData = $this->sanitizeData($data);
                
                return $this->cache->remember(
                    $this->getCacheKey($template, $safeData),
                    function() use ($validated, $safeData) {
                        return $this->renderTemplate($validated, $safeData);
                    }
                );
            },
            $context
        );
    }

    public function compile(string $template, SecurityContext $context): CompiledTemplate
    {
        return $this->protection->executeProtectedOperation(
            function() use ($template, $context) {
                $validated = $this->validateTemplate($template);
                $compiled = $this->compiler->compile($validated);
                
                $this->validateCompiled($compiled);
                $this->cacheCompiled($compiled);
                
                return $compiled;
            },
            $context
        );
    }

    public function extend(string $name, callable $extension, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($name, $extension, $context) {
                $this->validateExtension($name, $extension);
                $this->registerExtension($name, $extension);
                $this->invalidateRelatedCache($name);
            },
            $context
        );
    }

    public function registerFunction(string $name, callable $function, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($name, $function, $context) {
                $this->validateFunction($name, $function);
                $this->security->validateCallback($function);
                $this->registerSafeFunction($name, $function);
            },
            $context
        );
    }

    private function validateTemplate(string $template): string
    {
        $rules = [
            'syntax' => 'valid',
            'security' => 'safe',
            'performance' => 'optimized'
        ];

        if (!$this->validator->validate($template, $rules)) {
            throw new TemplateException('Template validation failed');
        }

        return $template;
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->security->sanitizeString($value);
            } elseif (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    private function renderTemplate(string $template, array $data): string
    {
        $this->metrics->startMeasure('template_render');
        
        try {
            $compiled = $this->compiler->compile($template);
            $rendered = $this->executeTemplate($compiled, $data);
            
            $this->validateOutput($rendered);
            $this->metrics->endMeasure('template_render');
            
            return $rendered;
            
        } catch (\Throwable $e) {
            $this->handleRenderError($e, $template);
            throw $e;
        }
    }

    private function validateCompiled(CompiledTemplate $compiled): void
    {
        if (!$compiled->validate()) {
            throw new TemplateException('Compiled template validation failed');
        }

        $this->security->validateCode($compiled->getCode());
    }

    private function cacheCompiled(CompiledTemplate $compiled): void
    {
        $this->cache->tags(['templates'])
            ->put(
                $this->getCompiledCacheKey($compiled),
                $compiled,
                config('cache.templates.ttl')
            );
    }

    private function validateExtension(string $name, callable $extension): void
    {
        if (!$this->validator->validateExtension($name, $extension)) {
            throw new TemplateException('Invalid template extension');
        }

        $this->security->validateCallback($extension);
    }

    private function validateFunction(string $name, callable $function): void
    {
        if (!$this->validator->validateFunction($name, $function)) {
            throw new TemplateException('Invalid template function');
        }

        if (!$this->security->isSafeCallback($function)) {
            throw new SecurityException('Unsafe template function');
        }
    }

    private function registerSafeFunction(string $name, callable $function): void
    {
        $safeFunction = $this->security->wrapCallback($function);
        $this->compiler->registerFunction($name, $safeFunction);
    }

    private function validateOutput(string $output): void
    {
        if (!$this->security->validateOutput($output)) {
            throw new SecurityException('Template output validation failed');
        }
    }
}
