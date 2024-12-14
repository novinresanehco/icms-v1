// File: app/Core/Template/Cache/TemplateCache.php
<?php

namespace App\Core\Template\Cache;

class TemplateCache
{
    protected CacheManager $cache;
    protected array $tags = ['templates'];
    protected int $ttl = 3600;

    public function get(Template $template, array $data): ?string
    {
        $key = $this->getCacheKey($template, $data);
        return $this->cache->tags($this->tags)->get($key);
    }

    public function put(Template $template, array $data, string $content): void
    {
        $key = $this->getCacheKey($template, $data);
        $this->cache->tags($this->tags)->put($key, $content, $this->ttl);
    }

    public function flush(): void
    {
        $this->cache->tags($this->tags)->flush();
    }

    protected function getCacheKey(Template $template, array $data): string
    {
        return sprintf(
            'template:%s:%s',
            $template->getId(),
            md5(serialize($data))
        );
    }
}
