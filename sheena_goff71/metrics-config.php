<?php

return [
    'flush_interval' => env('METRICS_FLUSH_INTERVAL', 60),
    
    'storage' => [
        'driver' => env('METRICS_STORAGE_DRIVER', 'database'),
        'table' => env('METRICS_TABLE', 'metrics'),
        'connection' => env('METRICS_DB_CONNECTION', null),
    ],
    
    'cache' => [
        'driver' => env('METRICS_CACHE_DRIVER', 'redis'),
        'prefix' => env('METRICS_CACHE_PREFIX', 'metrics:'),
        'ttl' => env('METRICS_CACHE_TTL', 3600),
    ],
    
    'collectors' => [
        'performance' => App\Core\Metrics\Collectors\PerformanceCollector::class,
        'resource' => App\Core\Metrics\Collectors\ResourceCollector::class,
    ],
    
    'aggregators' => [
        'database' => App\Core\Metrics\Aggregators\DatabaseAggregator::class,
    ],
    
    'processors' => [
        'validators' => [
            App\Core\Metrics\Processors\MetricValidator::class,
        ],
    ],
    
    'alerts' => [
        'enabled' => env('METRICS_ALERTS_ENABLED', true),
        'channels' => [
            'slack' => env('METRICS_ALERT_SLACK_WEBHOOK'),
            'email' => env('METRICS_ALERT_EMAIL'),
        ],
        'thresholds' => [
            'response_time' => env('METRICS_THRESHOLD_RESPONSE_TIME', 1000),
            'error_rate' => env('METRICS_THRESHOLD_ERROR_RATE', 0.05),
            'memory_usage' => env('METRICS_THRESHOLD_MEMORY_USAGE', 128),
        ],
    ],
];
