<?php

namespace App\Core\Config;

class RepositoryConfig
{
    public static function getConfig(): array
    {
        return [
            'cache' => [
                'enabled' => env('REPOSITORY_CACHE_ENABLED', true),
                'ttl' => env('REPOSITORY_CACHE_TTL', 3600),
                'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repository_'),
                'tags_enabled' => env('REPOSITORY_CACHE_TAGS_ENABLED', true),
            ],
            'events' => [
                'enabled' => env('REPOSITORY_EVENTS_ENABLED', true),
                'queue' => env('REPOSITORY_EVENTS_QUEUE', 'default'),
            ],
            'validation' => [
                'enabled' => env('REPOSITORY_VALIDATION_ENABLED', true),
                'throw_exceptions' => env('REPOSITORY_VALIDATION_THROW_EXCEPTIONS', true),
            ],
            'search' => [
                'enabled' => env('REPOSITORY_SEARCH_ENABLED', true),
                'engine' => env('REPOSITORY_SEARCH_ENGINE', 'elasticsearch'),
                'index_prefix' => env('REPOSITORY_SEARCH_INDEX_PREFIX', 'cms_'),
            ],
            'audit' => [
                'enabled' => env('REPOSITORY_AUDIT_ENABLED', true),
                'retention_days' => env('REPOSITORY_AUDIT_RETENTION_DAYS', 30),
                'excluded_fields' => ['password', 'remember_token'],
            ]
        ];
    }
}
