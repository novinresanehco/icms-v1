<?php

namespace App\Core\Security;

use App\Core\Exception\SecurityException;
use Psr\Log\LoggerInterface;

class SecurityService implements SecurityManagerInterface
{
    private LoggerInterface $logger;
    private EncryptionService $encryption;
    private AccessControl $access;
    private array $config;

    public function __construct(
        LoggerInterface $logger,
        EncryptionService $encryption,
        AccessControl $access,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->encryption = $encryption;
        $this->access = $access;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateContext(string $operation): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->validatePermissions($operation);
            $this->validateSecurityState();
            $this->validateResourceAccess($operation);

            $this->logSecurityCheck($operationId, $operation);
            
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($operationId, $operation, $e);
            throw $e;
        }
    }

    private function validatePermissions(string $operation): void
    {
        $user = $this->access->getCurrentUser();
        
        if (!$this->access->hasPermission($user, $operation)) {
            throw new SecurityException('Insufficient permissions for operation');
        }
    }

    private function validateSecurityState(): void
    {
        if (!$this->encryption->isStateValid()) {
            throw new SecurityException('Invalid security state');
        }

        if ($this->detectAnomalies()) {
            throw new SecurityException('Security anomalies detected');
        }
    }

    private function validateResourceAccess(string $operation): void
    {
        $resource = $this->getOperationResource($operation);
        
        if (!$this->access->canAccessResource($resource)) {
            throw new SecurityException('Resource access denied');
        }
    }

    private function detectAnomalies(): bool
    {
        $metrics = $this->collectSecurityMetrics();
        
        foreach ($metrics as $metric => $value) {
            if ($value > $this->config['anomaly_thresholds'][$metric]) {
                $this->logAnomalyDetected($metric, $value);
                return true;
            }
        }
        
        return false;
    }

    private function collectSecurityMetrics(): array
    {
        return [
            'failed_attempts' => $this->access->getFailedAttempts(),
            'suspicious_patterns' => $this->access->getSuspiciousPatterns(),
            'resource_violations' => $this->access->getResourceViolations()
        ];
    }

    private function generateOperationId(): string
    {
        return uniqid('sec_', true);
    }

    private function logSecurityCheck(string $operationId, string $operation): void
    {
        $this->logger->info('Security check completed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'user' => $this->access->getCurrentUser()?->getId(),
            'timestamp' => microtime(true)
        ]);
    }

    private function logAnomalyDetected(string $metric, $value): void
    {
        $this->logger->warning('Security anomaly detected', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->config['anomaly_thresholds'][$metric],
            'timestamp' => microtime(true)
        ]);
    }

    private function handleSecurityFailure(
        string $operationId,
        string $operation,
        \Exception $e
    ): void {
        $this->logger->error('Security validation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => microtime(true)
        ]);
    }

    private function getOperationResource(string $operation): string
    {
        return match($operation) {
            'validation:execute' => 'validation',
            'security:check' => 'security',
            default => throw new SecurityException("Unknown operation: {$operation}")
        };
    }

    private function getDefaultConfig(): array
    {
        return [
            'anomaly_thresholds' => [
                'failed_attempts' => 5,
                'suspicious_patterns' => 3,
                'resource_violations' => 2
            ],
            'security_level' => 'high',
            'encryption_algorithm' => 'aes-256-gcm',
            'key_rotation_interval' => 86400
        ];
    }
}
