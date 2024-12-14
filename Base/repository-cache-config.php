<?php

namespace App\Core\Cache;

class RepositoryCacheConfig
{
    /**
     * Default cache duration in seconds
     */
    protected const DEFAULT_TTL = 3600;

    /**
     * Cache tag prefix
     */
    protected const TAG_PREFIX = 'repo_';

    /**
     * Get cache tags for a specific repository
     */
    public static function getTags(string $repository): array
    {
        return [
            self::TAG_PREFIX . $repository,
            'repository_cache'
        ];
    }

    /**
     * Get cache key for repository method
     */
    public static function generateCacheKey(
        string $repository,
        string $method,
        array $arguments = []
    ): string {
        return sprintf(
            '%s:%s:%s',
            self::TAG_PREFIX . $repository,
            $method,
            md5(serialize($arguments))
        );
    }

    /**
     * Get cache TTL for repository
     */
    public static function getTTL(string $repository): int
    {
        return config(
            sprintf('cache.repository.%s.ttl', $repository), 
            self::DEFAULT_TTL
        );
    }
}
