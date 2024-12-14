<?php

namespace App\Core\Template;

class TemplateCacheManager
{
    private CacheInterface $cache;
    private SecurityManagerInterface $security;
    private array $config;
    
    public function __construct(
        CacheInterface $cache,
        SecurityManagerInterface $security,
        array $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->config = $config;
    }

    public function remember(string $template, array $data, int $ttl = null): string 
    {
        $key = $this->generateCacheKey($template, $data);
        $ttl = $ttl ?? $this->config['default_ttl'];

        if ($this->security->isHighRiskTemplate($template)) {
            $data = $this->security->sanitizeTemplateData($data);
            $ttl = min($ttl, $this->config['high_risk_ttl']);
        }

        return $this->cache->remember($key, $ttl, function() use ($template, $data) {
            return $this->renderTemplate($template, $data);
        });
    }

    private function renderTemplate(string $template, array $data): string 
    {
        $renderer = $this->security->validateTemplate($template);
        
        try {
            DB::beginTransaction();
            
            $result = $renderer->render($data);
            $this->validateOutput($result);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new TemplateException('Render failed: ' . $e->getMessage());
        }
    }

    private function validateOutput(string $output): void 
    {
        if (!$this->security->validateOutput($output)) {
            throw new SecurityException('Invalid template output');
        }
    }

    private function generateCacheKey(string $template, array $data): string 
    {
        return sprintf(
            'tpl:%s:%s',
            $template,
            hash('sha256', serialize($data))
        );
    }
}
