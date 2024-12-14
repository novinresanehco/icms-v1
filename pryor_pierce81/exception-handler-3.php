<?php

namespace App\Core\Exception;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use Psr\Log\LoggerInterface;

class ExceptionHandler implements ExceptionHandlerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function handle(\Throwable $exception, array $context = []): void
    {
        $incidentId = $this->monitor->startIncident($exception);

        try {
            // Log the exception
            $this->logException($exception, $incidentId, $context);

            // Handle specific exception types
            if ($exception instanceof SecurityException) {
                $this->handleSecurityException($exception, $incidentId);
            } elseif ($exception instanceof ValidationException) {
                $this->handleValidationException($exception, $incidentId);
            } elseif ($exception instanceof BusinessException) {
                $this->handleBusinessException($exception, $incidentId);
            } else {
                $this->handleGenericException($exception, $incidentId);
            }

            // Record incident
            $this->monitor->recordIncident($incidentId, [
                'type' => get_class($exception),
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'context' => $context
            ]);

        } catch (\Exception $e) {
            // Log handler failure
            $this->logger->critical('Exception handler failed', [
                'incident_id' => $incidentId,
                'original_exception' => get_class($exception),
                'handler_exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function handleSecurityException(SecurityException $exception, string $incidentId): void
    {
        // Log security incident
        $this->security->logSecurityIncident($exception);

        // Check for attack patterns
        if ($this->security->isAttackAttempt($exception)) {
            $this->security->blockAttacker($exception->getContext());
        }

        // Notify security team
        if ($this->isCriticalSecurity($exception)) {
            $this->notifySecurityTeam($exception, $incidentId);
        }
    }

    private function handleValidationException(ValidationException $exception, string $incidentId): void
    {
        // Log validation details
        $this->logger->warning('Validation failed', [
            'incident_id' => $incidentId,
            'field' => $exception->getField(),
            'constraint' => $exception->getConstraint(),
            'value' => $exception->getValue()
        ]);

        // Update validation metrics
        $this->monitor->recordValidationFailure($exception->getField());
    }

    private function handleBusinessException(BusinessException $exception, string $incidentId): void
    {
        // Log business error
        $this->logger->error('Business rule violation', [
            'incident_id' => $incidentId,
            'rule' => $exception->getRule(),
            'details' => $exception->getDetails()
        ]);

        // Update business metrics
        $this->monitor->recordBusinessFailure($exception->getRule());
    }

    private function handleGenericException(\Throwable $exception, string $incidentId): void
    {
        // Log generic error
        $this->logger->error('System error occurred', [
            'incident_id' => $incidentId,
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Check system health
        $this->monitor->checkSystemHealth();
    }

    private function logException(\Throwable $exception, string $incidentId, array $context): void
    {
        $this->logger->error('Exception occurred', [
            'incident_id' => $incidentId,
            'type' => get_class($exception),
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    private function isCriticalSecurity(SecurityException $exception): bool
    {
        return in_array($exception->getCode(), $this->config['critical_security_codes']);
    }

    private function notifySecurityTeam(SecurityException $exception, string $incidentId): void
    {
        // Implementation for security team notification
    }

    private function getDefaultConfig(): array
    {
        return [
            'critical_security_codes' => [
                SecurityException::ATTACK_DETECTED,
                SecurityException::AUTHENTICATION_BREACH,
                SecurityException::DATA_BREACH
            ],
            'log_level_map' => [
                SecurityException::class => 'critical',
                ValidationException::class => 'warning',
                BusinessException::class => 'error'
            ]
        ];
    }
}
