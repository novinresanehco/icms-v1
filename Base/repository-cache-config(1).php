<?php

return [
    'enabled' => env('REPOSITORY_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Duration Settings
    |--------------------------------------------------------------------------
    | Default cache durations for different types of repository queries
    */
    'ttl' => [
        'default' => 3600, // 1 hour
        'short' => 300,    // 5 minutes
        'medium' => 1800,  // 30 minutes
        'long' => 86400,   // 24 hours
        
        // Entity-specific cache durations
        'entities' => [
            'content' => [
                'single' => 1800,     // 30 minutes
                'list' => 3600,       // 1 hour
                'popular' => 900,      // 15 minutes
                'search' => 300,       // 5 minutes
            ],
            'category' => [
                'tree' => 86400,      // 24 hours
                'list' => 3600,       // 1 hour
            ],
            'media' => [
                'single' => 86400,    // 24 hours
                'list' => 3600,       // 1 hour
                'metadata' => 86400,   // 24 hours
            ],
            'tag' => [
                'list' => 3600,       // 1 hour
                'popular' => 1800,     // 30 minutes
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Tags Configuration
    |--------------------------------------------------------------------------
    | Define cache tag groups for different entities
    */
    'tags' => [
        'content' => ['content', 'front-page', 'feed'],
        'category' => ['category', 'navigation', 'front-page'],
        'media' => ['media', 'content'],
        'tag' => ['tag', 'content'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Store Configuration
    |--------------------------------------------------------------------------
    | Configure which cache store to use for repository caching
    */
    'store' => env('REPOSITORY_CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    | Prefix for all cache keys to avoid collisions
    */
    'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repo_'),

    /*
    |--------------------------------------------------------------------------
    | Cache Invalidation Rules
    |--------------------------------------------------------------------------
    | Define which caches should be cleared when specific events occur
    */
    'invalidation' => [
        'content' => [
            'on_create' => ['content', 'front-page', 'feed'],
            'on_update' => ['content', 'front-page', 'feed'],
            'on_delete' => ['content', 'front-page', 'feed', 'category'],
        ],
        'category' => [
            'on_create' => ['category', 'navigation', 'front-page'],
            'on_update' => ['category', 'navigation', 'front-page'],
            'on_delete' => ['category', 'navigation', 'front-page', 'content'],
        ],
        'media' => [
            'on_create' => ['media'],
            'on_update' => ['media', 'content'],
            'on_delete' => ['media', 'content'],
        ],
        'tag' => [
            'on_create' => ['tag', 'content'],
            'on_update' => ['tag', 'content'],
            'on_delete' => ['tag', 'content'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Warming Configuration 
    |--------------------------------------------------------------------------
    | Configure which caches should be pre-warmed
    */
    'warming' => [
        'enabled' => env('REPOSITORY_CACHE_WARMING_ENABLED', true),
        'schedule' => '0 * * * *', // Run hourly
        'entities' => [
            'category' => ['tree', 'list'],
            'content' => ['popular', 'recent'],
            'tag' => ['popular'],
        ],
    ],
];
