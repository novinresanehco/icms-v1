<?php

namespace App\Core\Logging;

use App\Core\Contracts\AuditInterface;
use App\Core\Contracts\LoggerInterface;
use Illuminate\Support\Facades\Log;

class AuditLogger implements AuditInterface
{
    private LoggerInterface $logger;
    private array $sensitiveFields = ['password', 'token', 'secret'];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function logSecurityEvent(string $event, array $context): void
    {
        $sanitizedContext = $this->sanitizeContext($context);
        
        $this->logger->critical($event, [
            'event_type' => 'security',
            'timestamp' => microtime(true),
            'context' => $sanitizedContext
        ]);
    }

    public function logValidationFailure(array $data, array $errors): void
    {
        $sanitizedData = $this->sanitizeContext($data);
        
        $this->logger->warning('Validation failure', [
            'event_type' => 'validation',
            'timestamp' => microtime(true),
            'data' => $sanitizedData,
            'errors' => $errors
        ]);
    }

    public function logSystemFailure(\Exception $e): void
    {
        $this->logger->critical('System failure', [
            'event_type' => 'system_failure',
            'timestamp' => microtime(true),
            'exception' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }

    public function logTokenCreation(Token $token): void
    {
        $this->logger->info('Token created', [
            'event_type' => 'token_creation',
            'timestamp' => microtime(true),
            'token_id' => $token->getId(),
            'user_id' => $token->getUserId(),
            'expires_at' => $token->getExpiresAt()
        ]);
    }

    public function logTokenValidationFailure(string $token, \Exception $e): void
    {
        $this->logger->warning('Token validation failed', [
            'event_type' => 'token_validation_failure',
            'timestamp' => microtime(true),
            'token_id' => substr($token, 0, 8) . '...',
            'error' => $e->getMessage()
        ]);
    }

    public function logTokenRevocation(string $token): void
    {
        $this->logger->info('Token revoked', [
            'event_type' => 'token_revocation',
            'timestamp' => microtime(true),
            'token_id' => substr($token, 0, 8) . '...'
        ]);
    }

    private function sanitizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array($key, $this->sensitiveFields)) {
                $context[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            }
        }

        return $context;
    }
}
