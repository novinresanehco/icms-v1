<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{Cache, View};
use App\Core\Security\{SecurityManager, ValidationService};

class TemplateEngine
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $registeredComponents = [];
    private array $activeContext = [];

    public function render(string $template, array $data = []): string
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $this->security->validateAccess('template', 'render');
            $this->validateTemplateContext($template, $data);
            
            $cacheKey = $this->generateRenderCacheKey($template, $data);
            
            if ($cached = $this->getCachedRender($cacheKey)) {
                return $cached;
            }
            
            $processed = $this->processTemplate($template, $data);
            $rendered = $this->renderTemplate($processed, $data);
            $validated = $this->validateOutput($rendered);
            
            $this->cacheRender($cacheKey, $validated);
            $this->auditRender($template, $data, $startTime);
            
            DB::commit();
            return $validated;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRenderFailure($e, $template, $data);
            throw $e;
        }
    }

    public function registerComponent(string $name, callable $renderer): void
    {
        $this->security->validateAccess('template', 'register_component');
        
        if (isset($this->registeredComponents[$name])) {
            throw new TemplateException("Component {$name} already registered");
        }
        
        $this->registeredComponents[$name] = [
            'renderer' => $renderer,
            'hash' => $this->generateComponentHash($name, $renderer),
            'registered_at' => microtime(true)
        ];
        
        $this->cache->invalidateComponentCache();
        $this->audit->logComponentRegistration($name);
    }

    public function compileTemplate(string $template): CompiledTemplate
    {
        $this->security->validateAccess('template', 'compile');
        
        return DB::transaction(function() use ($template) {
            $compiled = new CompiledTemplate([
                'source' => $template,
                'compiled' => $this->compile($template),
                'hash' => $this->generateTemplateHash($template),
                'compiled_at' => now()
            ]);
            
            $this->cache->invalidateTemplateCache();
            $this->audit->logTemplateCompilation($compiled);
            
            return $compiled;
        });
    }

    private function processTemplate(string $template, array $data): ProcessedTemplate
    {
        $this->activeContext = [
            'template' => $template,
            'data' => $data,
            'start_time' => microtime(true)
        ];
        
        $processed = $this->preProcess($template);
        $processed = $this->injectSecurityHeaders($processed);
        $processed = $this->resolveComponents($processed);
        
        return new ProcessedTemplate($processed);
    }

    private function preProcess(string $template): string
    {
        $template = $this->removeMaliciousCode($template);
        $template = $this->sanitizeTemplate($template);
        return $template;
    }

    private function removeMaliciousCode(string $template): string
    {
        $patterns = $this->config->getMaliciousCodePatterns();
        return preg_replace($patterns, '', $template);
    }

    private function sanitizeTemplate(string $template): string
    {
        return strip_tags(
            $template,
            $this->config->getAllowedTemplateTags()
        );
    }

    private function resolveComponents(string $template): string
    {
        return preg_replace_callback(
            '/@component\(([^)]+)\)/',
            [$this, 'renderComponent'],
            $template
        );
    }

    private function renderComponent(array $matches): string
    {
        $name = trim($matches[1], '"\'');
        
        if (!isset($this->registeredComponents[$name])) {
            throw new TemplateException("Unknown component: {$name}");
        }
        
        return call_user_func(
            $this->registeredComponents[$name]['renderer'],
            $this->activeContext['data']
        );
    }

    private function validateTemplateContext(string $template, array $data): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateValidationException('Invalid template structure');
        }
        
        if (!$this->validator->validateTemplateData($data)) {
            throw new TemplateValidationException('Invalid template data');
        }
    }

    private function validateOutput(string $output): string
    {
        if (!$this->validator->validateTemplateOutput($output)) {
            throw new TemplateValidationException('Invalid template output');
        }
        
        return $this->sanitizeOutput($output);
    }

    private function sanitizeOutput(string $output): string
    {
        return htmlspecialchars(
            $output,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    private function generateRenderCacheKey(string $template, array $data): string
    {
        return 'template_render:' . hash('sha256', $template . serialize($data));
    }

    private function generateTemplateHash(string $template): string
    {
        return hash_hmac(
            'sha256',
            $template,
            $this->config->getSecurityKey()
        );
    }

    private function generateComponentHash(string $name, callable $renderer): string
    {
        return hash_hmac(
            'sha256',
            $name . serialize($renderer),
            $this->config->getSecurityKey()
        );
    }

    private function getCachedRender(string $key): ?string
    {
        if ($this->config->isTemplateCacheEnabled()) {
            return Cache::get($key);
        }
        return null;
    }

    private function cacheRender(string $key, string $output): void
    {
        if ($this->config->isTemplateCacheEnabled()) {
            Cache::put(
                $key,
                $output,
                $this->config->getTemplateCacheDuration()
            );
        }
    }

    private function auditRender(string $template, array $data, float $startTime): void
    {
        $this->audit->logTemplateRender([
            'template' => $template,
            'data_hash' => hash('sha256', serialize($data)),
            'duration' => microtime(true) - $startTime,
            'memory_peak' => memory_get_peak_usage(true)
        ]);
    }
}
