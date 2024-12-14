<?php

return [
    'security' => [
        'session' => [
            'lifetime' => 900,
            'encrypt' => true,
            'secure' => true,
            'same_site' => 'lax',
        ],
        'auth' => [
            'throttle' => [
                'max_attempts' => 5,
                'decay_minutes' => 15
            ],
            '2fa' => [
                'enabled' => true,
                'timeout' => 300
            ]
        ],
        'headers' => [
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ]
    ],
    'cache' => [
        'default' => 'redis',
        'prefix' => 'cms_',
        'ttl' => 3600,
        'tags_enabled' => true
    ],
    'database' => [
        'connections' => [
            'mysql' => [
                'strict' => true,
                'engine' => 'InnoDB',
                'timezone' => '+00:00',
                'options' => [
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false
                ]
            ]
        ],
        'monitoring' => [
            'slow_query_threshold' => 1000,
            'log_queries' => true
        ]
    ],
    'monitoring' => [
        'metrics' => [
            'enabled' => true,
            'retention_days' => 30
        ],
        'alerts' => [
            'channels' => ['slack', 'email'],
            'thresholds' => [
                'response_time' => 500,
                'error_rate' => 0.01,
                'memory_usage' => 80
            ]
        ]
    ]
];

// Deployment script
namespace Deploy;

class DeploymentManager 
{
    public static function deploy(): void 
    {
        self::preDeploymentChecks();
        
        DB::beginTransaction();
        try {
            self::backupDatabase();
            self::migrateDatabase();
            self::clearCache();
            self::updateConfigurations();
            self::optimizeAutoloader();
            self::restartQueue();
            
            DB::commit();
            
            self::postDeploymentChecks();
        } catch (\Exception $e) {
            DB::rollBack();
            self::rollback();
            throw $e;
        }
    }

    private static function preDeploymentChecks(): void 
    {
        if (!self::checkDiskSpace() || 
            !self::checkDatabaseConnection() || 
            !self::checkRedisConnection()) {
            throw new DeploymentException('Pre-deployment checks failed');
        }
    }

    private static function backupDatabase(): void 
    {
        $filename = 'backup_' . time() . '.sql';
        exec("mysqldump -u{$user} -p{$pass} {$database} > {$filename}");
    }

    private static function migrateDatabase(): void 
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    private static function clearCache(): void 
    {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
    }

    private static function updateConfigurations(): void 
    {
        config(['app.env' => 'production']);
        config(['app.debug' => false]);
        
        Artisan::call('config:cache');
        Artisan::call('route:cache');
        Artisan::call('view:cache');
    }

    private static function optimizeAutoloader(): void 
    {
        exec('composer install --no-dev --optimize-autoloader');
    }

    private static function restartQueue(): void 
    {
        Artisan::call('queue:restart');
    }

    private static function postDeploymentChecks(): void 
    {
        if (!self::verifyApplicationHealth()) {
            self::rollback();
            throw new DeploymentException('Post-deployment checks failed');
        }
    }

    private static function rollback(): void 
    {
        $backup = glob('backup_*.sql')[0] ?? null;
        if ($backup) {
            exec("mysql -u{$user} -p{$pass} {$database} < {$backup}");
        }
    }

    private static function verifyApplicationHealth(): bool 
    {
        return 
            self::checkApplicationResponse() && 
            self::checkDatabaseConnectivity() && 
            self::checkCacheConnectivity() && 
            self::checkQueueProcessing();
    }
}
