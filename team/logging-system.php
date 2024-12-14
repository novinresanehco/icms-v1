<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManager;
use Monolog\Logger;
use Monolog\Handler\{StreamHandler, RotatingFileHandler};
use Illuminate\Support\Facades\Storage;

class LogManager
{
    private SecurityManager $security;
    private array $loggers = [];
    private array $config;

    private const LOG_CHANNELS = [
        'security' => [
            'path' => 'security/security.log',
            'level' => Logger::INFO,
            'days' => 90
        ],
        'critical' => [
            'path' => 'critical/critical.log',
            'level' => Logger::CRITICAL,
            'days' => 365
        ],
        'performance' => [
            'path' => 'performance/performance.log',
            'level' => Logger::INFO,
            'days' => 30
        ]
    ];

    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        $logger = $this->getLogger($channel);
        
        try {
            // Secure context data
            $secureContext = $this->secureContextData($context);
            
            // Add standard metadata
            $secureContext = $this->addMetadata($secureContext);
            
            // Write log
            $logger->{$level}($message, $secureContext);
            
            // Check for critical conditions
            $this->checkCriticalConditions($level, $message, $secureContext);
            
        } catch (\Throwable $e) {
            $this->handleLoggingFailure($e, $channel, $level, $message);
        }
    }

    public function logSecurity(string $event, array $context = []): void
    {
        $this->log('security', 'info', $event, array_merge($context, [
            'security_context' => $this->security->getSecurityContext()
        ]));
    }

    public function logCritical(string $message, array $context = []): void
    {
        $this->log('critical', 'critical', $message, $context);
    }

    public function logPerformance(array $metrics): void
    {
        $this->log('performance', 'info', 'Performance metrics', $metrics);
    }

    private function getLogger(string $channel): Logger
    {
        if (!isset($this->loggers[$channel])) {
            $this->loggers[$channel] = $this->createLogger($channel);
        }

        return $this->loggers[$channel];
    }

    private function createLogger(string $channel): Logger
    {
        $config = self::LOG_CHANNELS[$channel];
        
        $logger = new Logger($channel);
        
        // Add rotating file handler
        $logger->pushHandler(
            new RotatingFileHandler(
                storage_path("logs/{$config['path']}"),
                $config['days'],
                $config['level']
            )
        );
        
        // Add stream handler for immediate monitoring
        $logger->pushHandler(
            new StreamHandler(
                storage_path("logs/{$channel}_current.log"),
                $config['level']
            )
        );

        return $logger;
    }

    private function secureContextData(array $context): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->secureContextData($value);
            }

            if ($this->isCredential($value)) {
                return '[REDACTED]';
            }

            return $value;
        }, $context);
    }

    private function addMetadata(array $context): array
    {
        return array_merge($context, [
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'url' => request()->fullUrl(),
            'method' => request()->method()
        ]);
    }

    private function checkCriticalConditions(string $level, string $message, array $context): void
    {
        if ($level === 'critical' || $level === 'emergency') {
            $this->security->notifyAdministrators('critical_log', [
                'level' => $level,
                'message' => $message,
                'context' => $context
            ]);
        }
    }

    private function handleLoggingFailure(\Throwable $e, string $channel, string $level, string $message): void
    {
        try {
            // Attempt to log to emergency channel
            $emergencyLogger = new Logger('emergency');
            $emergencyLogger->pushHandler(
                new StreamHandler(
                    storage_path('logs/emergency.log'),
                    Logger::EMERGENCY
                )
            );

            $emergencyLogger->emergency('Logging system failure', [
                'original_channel' => $channel,
                'original_level' => $level,
                'original_message' => $message,
                'error' => [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            ]);
        } catch (\Throwable) {
            // Silent fail - we've done our best
        }
    }

    private function isCredential($value): bool
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        
        if (is_string($value)) {
            foreach ($sensitiveKeys as $key) {
                if (stripos($value, $key) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
