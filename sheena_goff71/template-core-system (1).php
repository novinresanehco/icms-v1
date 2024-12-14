<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Log};
use Illuminate\Contracts\View\Factory;

class TemplateManager
{
    protected ValidationService $validator;
    protected SecurityManager $security;
    protected CacheManager $cache;

    public function render(string $template, array $data): string
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->renderTemplate($template, $data),
            ['action' => 'render', 'template' => $template]
        );
    }

    protected function renderTemplate(string $template, array $data): string
    {
        $this->validator->validateTemplate($template);
        $this->validator->validateData($data);

        $cacheKey = $this->getCacheKey($template, $data);

        return $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
            $compiled = $this->compile($template, $data);
            $this->auditRender($template);
            return $compiled;
        });
    }

    protected function compile(string $template, array $data): string
    {
        try {
            return View::make($template, $this->sanitizeData($data))->render();
        } catch (\Throwable $e) {
            Log::error('Template compilation failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            throw new TemplateException('Failed to compile template');
        }
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template:' . sha1($template . serialize($data));
    }

    protected function auditRender(string $template): void
    {
        Log::info('Template rendered', [
            'template' => $template,
            'user' => auth()->id(),
            'timestamp' => now()
        ]);
    }
}

class ValidationService
{
    public function validateTemplate(string $template): void
    {
        if (!$this->isValidTemplatePath($template)) {
            throw new SecurityException('Invalid template path');
        }

        if (!$this->templateExists($template)) {
            throw new TemplateException('Template not found');
        }
    }

    public function validateData(array $data): void
    {
        array_walk_recursive($data, function($value) {
            if ($this->containsMaliciousContent($value)) {
                throw new SecurityException('Malicious content detected');
            }
        });
    }

    protected function isValidTemplatePath(string $template): bool
    {
        return !preg_match('/\.\.[\/\\\\]/', $template);
    }

    protected function templateExists(string $template): bool
    {
        return View::exists($template);
    }

    protected function containsMaliciousContent($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        $patterns = [
            '/(<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>)/is',
            '/(javascript\s*:)/i',
            '/(\b(on\w+)\s*=)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}

class SecurityManager
{
    protected AuditLogger $audit;

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        try {
            $this->validateAccess($context);
            $result = $operation();
            $this->auditOperation($context);
            return $result;
        } catch (\Throwable $e) {
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    protected function validateAccess(array $context): void
    {
        if (!$this->hasPermission($context['action'])) {
            throw new UnauthorizedException('Insufficient permissions');
        }
    }

    protected function hasPermission(string $action): bool
    {
        return auth()->user()?->can($action) ?? false;
    }

    protected function auditOperation(array $context): void
    {
        $this->audit->log('template.operation', $context);
    }

    protected function handleFailure(\Throwable $e, array $context): void
    {
        Log::error('Template operation failed', [
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class CacheManager
{
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning('Cache operation failed', ['key' => $key]);
            return $callback();
        }
    }

    public function forget(string $key): bool
    {
        return Cache::forget($key);
    }
}

class TemplateException extends \Exception {}
class SecurityException extends \Exception {}
class UnauthorizedException extends \Exception {}
