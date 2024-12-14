<?php

return [
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-GCM',
    'options' => [
        'tag_length' => 16,
        'key_iterations' => 100000,
        'memory_limit' => 1024 * 1024 * 32, // 32MB for key derivation
    ],
    'protection' => [
        'at_rest' => true,
        'in_transit' => true,
        'key_rotation' => 'daily',
    ],
    'algorithms' => [
        'hash' => 'sha256',
        'hmac' => 'sha256',
        'kdf' => 'pbkdf2',
    ],
    'security' => [
        'enforce_encryption' => true,
        'verify_integrity' => true,
        'log_operations' => true,
    ],
    'monitoring' => [
        'track_usage' => true,
        'alert_on_failure' => true,
        'log_level' => 'error',
    ],
];
