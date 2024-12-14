<?php

return [
    'security' => [
        'critical_operations' => [
            'content_management' => [
                'risk_level' => 'high',
                'required_permissions' => ['content.manage'],
                'validation_rules' => [
                    'input' => ['strict' => true],
                    'output' => ['sanitize' => true]
                ],
                'protection' => [
                    'rate_limit' => true,
                    'audit_log' => true,
                    'encryption' => true
                ]
            ],
            'user_management' => [
                'risk_level' => 'critical',
                'required_permissions' => ['users.manage'],
                'validation_rules' => [
                    'input' => ['strict' => true],
                    'output' => ['sanitize' => true]
                ],
                'protection' => [
                    'rate_limit' => true,
                    'audit_log' => true,
                    'encryption' => true,
                    'multi_factor' => true
                ]
            ]
        ],

        'validation_rules' => [
            'input' => [
                'sanitize' => true,
                'encode' => true,
                'max_length' => 1000,
                'allowed_tags' => []
            ],
            'output' => [
                'sanitize' => true,
                'encode' => true,
                'remove_scripts' => true
            ]
        ],

        'protection' => [
            'rate_limiting' => [
                'enabled' => true,
                'max_attempts' => 60,
                'decay_minutes' => 1
            ],
            'encryption' => [
                'algorithm' => 'AES-256-GCM',
                'key_rotation' => 86400
            ],
            'audit' => [
                'enabled' => true,
                'detailed' => true,
                'retention' => 90
            ]
        ],

        'monitoring' => [
            'real_time' => true,
            'metrics' => [
                'collection_interval' => 60,
                'retention_period' => 2592000
            ],
            'alerts' => [
                'channels' => ['email', 'slack'],
                'thresholds' => [
                    'error_rate' => 0.01,
                    'response_time' => 500,
                    'failure_count' => 3
                ]
            ]
        ],

        'authentication' => [
            'multi_factor' => [
                'enabled' => true,
                'methods' => ['totp', 'email'],
                'validity' => 300
            ],
            'session' => [
                'lifetime' => 900,
                'secure' => true,
                'http_only' => true
            ],
            'tokens' => [
                'length' => 64,
                'algorithm' => 'sha256',
                'rotation' => 3600
            ]
        ],

        'authorization' => [
            'strict_mode' => true,
            'cache_ttl' => 300,
            'default_policy' => 'deny',
            'super_admin_role' => 'super_admin'
        ],

        'sensitive_data' => [
            'fields' => [
                'password',
                'token',
                'api_key',
                'credit_card',
                'social_security'
            ],
            'encryption' => true,
            'masking' => true,
            'audit_access' => true
        ],

        'compliance' => [
            'standards' => [
                'pci_dss' => true,
                'gdpr' => true,
                'hipaa' => false
            ],
            'audit_trail' => true,
            'data_retention' => [
                'logs' => 90,
                'audit_trails' => 365,
                'backups' => 30
            ]
        ]
    ]
];
