<?php

return [
    'tags' => [
        'store' => 'redis',
        'prefix' => 'tags:',
        'ttl' => 3600,
        'tags' => [
            'enabled' => true,
            'store' => 'redis'
        ],
        'invalidation' => [
            'strategy' => 'all',
            'events' => [
                'created',
                'updated', 
                'deleted'
            ]
        ]
    ]
];
