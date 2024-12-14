<?php

namespace App\Core\Template;

use App\Core\Interfaces\TemplateInterface;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\View;
use Illuminate\Contracts\View\Factory;

class TemplateEngine implements TemplateInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private Factory $view;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        Factory $view
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->view = $view;
    }

    public function render(string $template, array $data = []): string 
    {
        try {
            // Validate template and data
            $this->validateTemplate($template);
            $data = $this->sanitizeData($data);

            // Get rendered content with caching
            return $this->cache->remember(
                "template.{$template}." . md5(serialize($data)),
                config('cache.ttl.templates'),
                fn() => $this->renderTemplate($template, $data)
            );
        } catch (\Exception $e) {
            // Log error and render fallback
            $this->logError($e);
            return $this->renderError();
        }
    }

    protected function validateTemplate(string $template): void 
    {
        if (!$this->view->exists($template)) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }
        
        // Additional security checks
        $this->security->validateTemplate($template);
    }

    protected function sanitizeData(array $data): array 
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->security->sanitize($value);
            }
            return $value;
        }, $data);
    }

    protected function renderTemplate(string $template, array $data): string 
    {
        try {
            DB::beginTransaction();

            // Render with security context
            $result = $this->view->make($template, $data)->render();
            
            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function extendWith(string $name, callable $extension): void 
    {
        // Register template extension with security validation
        $this->security->validateExtension($name);
        $this->view->share($name, $extension);
    }

    public function clear(string $template = null): void 
    {
        if ($template) {
            $this->cache->forget("template.{$template}");
        } else {
            $this->cache->tags(['templates'])->flush();
        }
    }

    protected function logError(\Exception $e): void 
    {
        Log::error('Template rendering failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function renderError(): string 
    {
        return View::make('errors.template')->render();
    }
}
