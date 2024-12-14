<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManagerInterface;
use Psr\Log\LoggerInterface;

class LoggingService implements LoggerInterface
{
    private SecurityManagerInterface $security;
    private array $handlers;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        array $handlers = [],
        array $config = []
    ) {
        $this->security = $security;
        $this->handlers = $handlers;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $this->secureContext($context));
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $this->secureContext($context));
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $this->secureContext($context));
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $this->secureContext($context));
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $this->secureContext($context));
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $this->secureContext($context));
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $this->secureContext($context));
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $this->secureContext($context));
    }

    public function log($level, $message, array $context = []): void
    {
        $entry = $this->createLogEntry($level, $message, $context);
        
        foreach ($this->handlers as $handler) {
            if ($this->shouldHandle($handler, $level)) {
                $handler->handle($entry);
            }
        }
    }

    private function createLogEntry(string $level, string $message, array $context): array
    {
        return [
            'timestamp' => microtime(true),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'request_id' => $this->security->getRequestId(),
            'session_id' => $this->security->getSessionId(),
            'user_id' => $this->security->getCurrentUserId(),
            'ip' => $this->security->getClientIp(),
            'process_id' => getmypid()
        ];
    }

    private function secureContext(array $context): array
    {
        // Remove sensitive data
        foreach ($this->config['sensitive_fields'] as $field) {
            if (isset($context[$field])) {
                $context[$field] = '[REDACTED]';
            }
        }

        // Add security context
        $context['security_context'] = [
            'user_id' => $this->security->getCurrentUserId(),
            'roles' => $this->security->getCurrentUserRoles(),
            'permissions' => $this->security->getCurrentUserPermissions()
        ];

        return $context;
    }

    private function shouldHandle($handler, string $level): bool
    {
        return $handler->isHandling($level);
    }

    private function getDefaultConfig(): array
    {
        return [
            'sensitive_fields' => [
                'password',
                'token',
                'api_key',
                'secret',
                'credit_card'
            ],
            'min_level' => 'debug',
            'max_context_depth' => 5
        ];
    }
}
</antArtifact