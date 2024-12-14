<?php

namespace App\Core\CMS\Cache;

use App\Core\Cache\CacheManager;
use App\Core\Models\Content;
use Illuminate\Support\Facades\Cache;

class ContentCacheService
{
    private CacheManager $cache;
    private int $defaultTtl = 3600; // 1 hour

    /**
     * Implements content caching with security checks
     */
    public function cacheContent(Content $content): void
    {
        $key = $this->buildCacheKey($content);
        
        $this->cache->remember($key, function() use ($content) {
            return [
                'content' => $content->toArray(),
                'timestamp' => now()->timestamp,
                'hash' => $this->generateHash($content)
            ];
        }, $this->getCacheTtl($content));
    }

    /**
     * Retrieves cached content with validation
     */
    public function getCachedContent(int $id): ?Content
    {
        $key = "content:{$id}";
        
        $cached = $this->cache->get($key);
        
        if (!$cached) {
            return null;
        }

        // Validate cache integrity
        if (!$this->validateCacheIntegrity($cached)) {
            $this->cache->forget($key);
            return null;
        }

        return new Content($cached['content']);
    }

    /**
     * Invalidates content cache
     */
    public function invalidateContent(Content $content): void
    {
        // Clear specific content cache
        $this->cache->forget($this->buildCacheKey($content));

        // Clear related caches
        $this->cache->tags(['content', "content:{$content->id}"])->flush();
    }

    /**
     * Builds cache key for content
     */
    private function buildCacheKey(Content $content): string
    {
        return "content:{$content->id}:" . $this->generateHash($content);
    }

    /**
     * Generates content hash for cache validation
     */
    private function generateHash(Content $content): string
    {
        return hash('sha256', serialize([
            'content' => $content->toArray(),
            'updated_at' => $content->updated_at->timestamp
        ]));
    }

    /**
     * Validates cached content integrity
     */
    private function validateCacheIntegrity(array $cached): bool
    {
        return hash_equals(
            $cached['hash'],
            $this->generateHash(new Content($cached['content']))
        );
    }

    /**
     * Determines cache TTL based on content type
     */
    private function getCacheTtl(Content $content): int
    {
        return match($content->type) {
            'static' => 86400, // 24 hours
            'dynamic' => 1800, // 30 minutes
            default => $this->defaultTtl
        };
    }
}
