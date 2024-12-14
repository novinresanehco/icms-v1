<?php

return [
    'categories' => [
        'ttl' => env('CATEGORY_CACHE_TTL', 3600),
        'prefix' => env('CATEGORY_CACHE_PREFIX', 'category:'),
        'tags' => [
            'categories',
            'navigation',
            'sitemap'
        ],
        'keys' => [
            'tree' => 'categories.tree',
            'roots' => 'categories.roots',
            'types' => 'categories.types.',
            'item' => 'categories.item.',
            'children' => 'categories.children.'
        ]
    ]
];
