<?php

namespace App\Core\Template\Cache;

class TemplateCache
{
    private CacheManager $cache;
    private SecurityValidator $validator;
    private array $config;

    public function __construct(
        CacheManager $cache,
        SecurityValidator $validator,
        array $config = []
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = array_merge([
            'ttl' => 3600,
            'prefix' => 'template:',
            'versioning' => true
        ], $config);
    }

    public function remember(string $key, array $data, callable $generator): string
    {
        $cacheKey = $this->generateCacheKey($key, $data);
        
        return $this->cache->remember(
            $cacheKey,
            $this->config['ttl'],
            fn() => $this->processContent($generator())
        );
    }

    public function invalidate(string $template): void
    {
        $pattern = $this->config['prefix'] . $template . ':*';
        $this->cache->deletePattern($pattern);
    }

    private function generateCacheKey(string $key, array $data): string
    {
        $version = $this->config['versioning'] ? ':' . $this->getCurrentVersion() : '';
        return sprintf(
            '%s%s:%s%s',
            $this->config['prefix'],
            $key,
            md5(serialize($data)),
            $version
        );
    }

    private function processContent(string $content): string
    {
        return $this->validator->sanitizeContent($content);
    }

    private function getCurrentVersion(): string
    {
        return $this->cache->get('template:version', 'v1');
    }
}

class TemplateCacheWarmer
{
    private TemplateRegistry $registry;
    private TemplateCache $cache;
    private TemplateRenderer $renderer;

    public function __construct(
        TemplateRegistry $registry,
        TemplateCache $cache,
        TemplateRenderer $renderer
    ) {
        $this->registry = $registry;
        $this->cache = $cache;
        $this->renderer = $renderer;
    }

    public function warmCache(): void
    {
        foreach ($this->registry->getTemplates() as $template) {
            $this->warmTemplate($template);
        }
    }

    private function warmTemplate(Template $template): void
    {
        $variations = $template->getCacheVariations();
        foreach ($variations as $data) {
            $this->cache->remember(
                $template->getName(),
                $data,
                fn() => $this->renderer->render($template, $data)
            );
        }
    }
}

class TemplateCacheInvalidator
{
    private TemplateCache $cache;
    private array $dependencies;

    public function __construct(TemplateCache $cache)
    {
        $this->cache = $cache;
        $this->dependencies = [];
    }

    public function registerDependency(string $template, array $dependencies): void
    {
        $this->dependencies[$template] = $dependencies;
    }

    public function invalidateTemplate(string $template): void
    {
        $this->cache->invalidate($template);
        
        foreach ($this->dependencies[$template] ?? [] as $dependent) {
            $this->invalidateTemplate($dependent);
        }
    }
}

class TemplateCacheManager
{
    private TemplateCache $cache;
    private TemplateCacheWarmer $warmer;
    private TemplateCacheInvalidator $invalidator;
    private TemplateOptimizer $optimizer;

    public function __construct(
        TemplateCache $cache,
        TemplateCacheWarmer $warmer,
        TemplateCacheInvalidator $invalidator,
        TemplateOptimizer $optimizer
    ) {
        $this->cache = $cache;
        $this->warmer = $warmer;
        $this->invalidator = $invalidator;
        $this->optimizer = $optimizer;
    }

    public function optimize(): void
    {
        $this->optimizer->optimize();
        $this->warmer->warmCache();
    }

    public function invalidate(string $template): void
    {
        $this->invalidator->invalidateTemplate($template);
    }
}
