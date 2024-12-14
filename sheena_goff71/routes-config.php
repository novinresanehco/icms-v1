<?php

// routes/api.php
use App\Http\Controllers\{AuthController, ContentController};

Route::prefix('v1')->group(function () {
    // Auth routes
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::middleware('auth')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        
        // Content routes
        Route::apiResource('content', ContentController::class);
    });
});

// config/cms.php
return [
    'security' => [
        'session' => [
            'lifetime' => env('SESSION_LIFETIME', 24 * 60),
            'token_length' => 64,
            'refresh_interval' => 15
        ],
        'rate_limit' => [
            'max_attempts' => env('LOGIN_MAX_ATTEMPTS', 5),
            'decay_minutes' => env('LOGIN_DECAY_MINUTES', 15)
        ],
        'password' => [
            'min_length' => 8,
            'require_special' => true,
            'require_numbers' => true,
            'require_mixed_case' => true
        ]
    ],
    'cache' => [
        'ttl' => env('CACHE_TTL', 3600),
        'prefix' => env('CACHE_PREFIX', 'cms:'),
        'driver' => env('CACHE_DRIVER', 'redis')
    ],
    'content' => [
        'pagination' => [
            'per_page' => env('CONTENT_PER_PAGE', 15),
            'max_per_page' => 50
        ],
        'versions' => [
            'keep_max' => env('MAX_VERSIONS', 10),
            'auto_cleanup' => true
        ]
    ],
    'media' => [
        'max_size' => env('MEDIA_MAX_SIZE', 10240),
        'allowed_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf'
        ],
        'storage' => [
            'disk' => env('MEDIA_DISK', 'local'),
            'path' => env('MEDIA_PATH', 'media')
        ]
    ],
    'logging' => [
        'channel' => env('LOG_CHANNEL', 'cms'),
        'level' => env('LOG_LEVEL', 'warning'),
        'max_files' => env('LOG_MAX_FILES', 14)
    ]
];

// config/auth.php modifications
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users'
    ],
    'api' => [
        'driver' => 'token',
        'provider' => 'users',
        'hash' => true
    ]
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class
    ]
];
