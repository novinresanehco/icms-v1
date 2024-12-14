<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'permissions' => [
                'file' => [
                    'public' => 0644,
                    'private' => 0600,
                ],
                'dir' => [
                    'public' => 0755,
                    'private' => 0700,
                ],
            ],
        ],

        'secure' => [
            'driver' => 'local',
            'root' => storage_path('app/secure'),
            'permissions' => [
                'file' => [
                    'public' => 0600,
                    'private' => 0600,
                ],
                'dir' => [
                    'public' => 0700,
                    'private' => 0700,
                ],
            ],
            'encryption' => true,
            'cipher' => 'AES-256-CBC',
        ],
    ],

    'security' => [
        'max_size' => 10 * 1024 * 1024, // 10MB
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
            'text/plain',
            'application/json',
            'application/xml',
        ],
        'scan_uploads' => true,
        'verify_mime' => true,
        'enforce_permissions' => true,
    ],

    'backup' => [
        'enabled' => true,
        'frequency' => 'daily',
        'retention' => 30,
        'destination' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
        ],
    ],

    'monitoring' => [
        'enabled' => true,
        'events' => [
            'upload',
            'download',
            'delete',
            'error',
        ],
        'alerts' => [
            'disk_usage' => 90, // Percentage
            'error_rate' => 5, // Errors per minute
        ],
    ],
];
