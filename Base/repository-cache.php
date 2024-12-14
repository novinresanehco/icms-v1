<?php

namespace App\Core\Cache;

class RepositoryCacheConfig
{
    public static array $config = [
        'settings' => [
            'ttl' => 86400, // 24 hours
            'tags' => ['settings'],
        ],
        'taxonomies' => [
            'ttl' => 3600, // 1 hour
            'tags' => ['taxonomies', 'content'],
        ],
        'templates' => [
            'ttl' => 3600,
            'tags' => ['templates', 'themes'],
        ],
        'menus' => [
            'ttl' => 3600,
            'tags' => ['menus', 'navigation'],
        ],
        // Global cache tags
        'global_tags' => [
            'cms',
            'content',
            'system'
        ]
    ];

    public static function getTTL(string $repository): int
    {
        return static::$config[$repository]['ttl'] ?? 3600;
    }

    public static function getTags(string $repository): array
    {
        $tags = static::$config[$repository]['tags'] ?? [];
        return array_merge($tags, static::$config['global_tags']);
    }
}
