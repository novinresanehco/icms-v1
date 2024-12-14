<?php

namespace App\Core\Security;

class SecurityConfig
{
    public const CRITICAL_SETTINGS = [
        'auth' => [
            'multi_factor' => true,
            'session_timeout' => 900, // 15 minutes
            'max_attempts' => 3,
            'lockout_duration' => 1800 // 30 minutes
        ],
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation' => 86400, // 24 hours
        ],
        'headers' => [
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Content-Security-Policy' => "default-src 'self'",
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ],
        'validation' => [
            'sanitize_input' => true,
            'validate_content' => true,
            'check_integrity' => true
        ]
    ];
}
