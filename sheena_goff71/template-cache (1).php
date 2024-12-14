<?php

namespace App\Core\Template\Cache;

class TemplateCacheManager implements CacheInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private OptimizationEngine $optimizer;

    public function __construct(
        CacheStore $store,
        SecurityManager $security,
        OptimizationEngine $optimizer
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->optimizer = $optimizer;
    }

    public function remember(string $key, array $data, callable $callback): string
    {
        return DB::transaction(function() use ($key, $data, $callback) {
            $this->security->validateCacheOperation($key, $data);
            
            if ($cached = $this->store->get($key)) {
                return $this->verifyAndReturn($cached, $data);
            }

            $result = $callback();
            $optimized = $this->optimizer->optimize($result);
            $this->store->put($key, $optimized, $this->getTTL($data));
            
            return $optimized;
        });
    }

    public function invalidate(string $key): void
    {
        $this->store->forget($key);
    }

    private function verifyAndReturn(string $cached, array $data): string
    {
        $this->security->verifyCacheIntegrity($cached, $data);
        return $cached;
    }

    private function getTTL(array $data): int
    {
        return $data['cache_ttl'] ?? config('template.cache.ttl');
    }
}

class OptimizationEngine
{
    public function optimize(string $content): string
    {
        return $this->minify(
            $this->optimizeAssets(
                $this->processDeferLoading($content)
            )
        );
    }

    private function minify(string $content): string
    {
        return preg_replace([
            '/\>[^\S ]+/s',
            '/[^\S ]+\</s',
            '/(\s)+/s',
            '/<!--(.|\s)*?-->/'
        ], [
            '>',
            '<',
            '\\1',
            ''
        ], $content);
    }

    private function optimizeAssets(string $content): string
    {
        return preg_replace_callback(
            '/<(script|link|img)[^>]*>/',
            [$this, 'processAssetTag'],
            $content
        );
    }

    private function processDeferLoading(string $content): string
    {
        return preg_replace(
            '/<img(.+?)src=/i',
            '<img$1loading="lazy" src=',
            $content
        );
    }

    private function processAssetTag(array $matches): string
    {
        $tag = $matches[0];
        if (strpos($tag, 'defer') === false && strpos($tag, '<script') === 0) {
            $tag = str_replace('<script', '<script defer', $tag);
        }
        return $tag;
    }
}

class CacheStore implements CacheStoreInterface
{
    private array $stores = [];
    private array $tags = [];

    public function get(string $key): ?string
    {
        return $this->stores[$key] ?? null;
    }

    public function put(string $key, string $value, int $ttl): void
    {
        $this->stores[$key] = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
    }

    public function forget(string $key): void
    {
        unset($this->stores[$key]);
    }

    public function tag(string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
    }

    public function flush(): void
    {
        $this->stores = [];
        $this->tags = [];
    }
}

interface CacheInterface
{
    public function remember(string $key, array $data, callable $callback): string;
    public function invalidate(string $key): void;
}

interface CacheStoreInterface
{
    public function get(string $key): ?string;
    public function put(string $key, string $value, int $ttl): void;
    public function forget(string $key): void;
    public function flush(): void;
}
