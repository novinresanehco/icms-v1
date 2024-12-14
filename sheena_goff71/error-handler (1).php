<?php

namespace App\Core\Error;

use Illuminate\Support\Facades\{Log, Event};
use App\Core\Interfaces\{
    ErrorHandlerInterface,
    ValidationInterface,
    SecurityInterface,
    AuditInterface
};
use App\Core\Events\{SystemEvent, SecurityEvent};
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    BusinessException,
    SystemException
};

class ErrorHandler implements ErrorHandlerInterface
{
    protected ValidationInterface $validator;
    protected SecurityInterface $security;
    protected AuditInterface $audit;
    protected array $config;
    protected array $metrics;

    public function __construct(
        ValidationInterface $validator,
        SecurityInterface $security,
        AuditInterface $audit,
        array $config
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->audit = $audit;
        $this->config = $config;
        $this->initializeMetrics();
    }

    public function handleException(\Throwable $e, array $context = []): void
    {
        try {
            $this->validateContext($context);
            $this->trackException($e);

            if ($this->isCriticalException($e)) {
                $this->handleCriticalException($e, $context);
            } else {
                $this->handleStandardException($e, $context);
            }

            $this->notifyException($e, $context);
            $this->logException($e, $context);

        } catch (\Exception $innerException) {
            $this->handleHandlerFailure($e, $innerException);
        }
    }

    public function validateData(array $data, array $rules): array
    {
        try {
            return $this->validator->validate($data, $rules);
        } catch (ValidationException $e) {
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
    }

    public function validateOperation(string $operation, array $data): void
    {
        if (!$this->security->validateOperation($operation, $data)) {
            throw new SecurityException("Invalid operation: {$operation}");
        }
    }

    public function getErrorMetrics(): array
    {
        return $this->metrics;
    }

    protected function validateContext(array $context): void
    {
        $rules = [
            'source' => 'required|string',
            'operation' => 'required|string',
            'user_id' => 'nullable|integer'
        ];

        if (!$this->validator->validate($context, $rules)) {
            throw new ValidationException('Invalid error context');
        }
    }

    protected function trackException(\Throwable $e): void
    {
        $type = get_class($e);
        $this->metrics['exceptions'][$type] = 
            ($this->metrics['exceptions'][$type] ?? 0) + 1;

        if ($this->isCriticalException($e)) {
            $this->metrics['critical_exceptions']++;
        }

        $this->metrics['last_exception'] = [
            'type' => $type,
            'message' => $e->getMessage(),
            'time' => now()
        ];
    }

    protected function isCriticalException(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
            $e instanceof SystemException ||
            $this->isSystemCritical($e);
    }

    protected function isSystemCritical(\Throwable $e): bool
    {
        foreach ($this->config['critical_patterns'] as $pattern) {
            if (preg_match($pattern, $e->getMessage())) {
                return true;
            }
        }

        return false;
    }

    protected function handleCriticalException(\Throwable $e, array $context): void
    {
        // Immediate security measures
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityBreach($e, $context);
        }

        // System protection
        $this->executeEmergencyProtocol($e, $context);

        // Notify critical stakeholders
        $this->notifyCriticalStakeholders($e, $context);

        Event::dispatch(new SystemEvent('critical_error', [
            'exception' => $e,
            'context' => $context
        ]));
    }

    protected function handleStandardException(\Throwable $e, array $context): void
    {
        if ($e instanceof ValidationException) {
            $this->handleValidationException($e, $context);
        } elseif ($e instanceof BusinessException) {
            $this->handleBusinessException($e, $context);
        } else {
            $this->handleGenericException($e, $context);
        }
    }

    protected function handleValidationException(ValidationException $e, array $context): void
    {
        $this->audit->logValidationFailure($e, $context);
        $this->metrics['validation_failures']++;
    }

    protected function handleBusinessException(BusinessException $e, array $context): void
    {
        $this->audit->logBusinessError($e, $context);
        $this->metrics['business_errors']++;
    }

    protected function handleGenericException(\Throwable $e, array $context): void
    {
        $this->audit->logSystemError($e, $context);
        $this->metrics['system_errors']++;
    }

    protected function handleValidationFailure(ValidationException $e, array $data, array $rules): void
    {
        $this->audit->logValidationFailure($e, [
            'data' => $data,
            'rules' => $rules,
            'errors' => $e->errors()
        ]);

        $this->metrics['validation_failures']++;
    }

    protected function executeEmergencyProtocol(\Throwable $e, array $context): void
    {
        Event::dispatch(new SecurityEvent('emergency_protocol', [
            'exception' => $e,
            'context' => $context
        ]));

        foreach ($this->config['emergency_actions'] as $action) {
            $this->executeEmergencyAction($action, $e, $context);
        }
    }

    protected function executeEmergencyAction(string $action, \Throwable $e, array $context): void
    {
        try {
            $method = 'execute' . studly_case($action);
            if (method_exists($this, $method)) {
                $this->$method($e, $context);
            }
        } catch (\Exception $actionException) {
            $this->handleEmergencyActionFailure($action, $actionException);
        }
    }

    protected function notifyException(\Throwable $e, array $context): void
    {
        $notification = [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ];

        Event::dispatch(new SystemEvent('exception_occurred', $notification));
    }

    protected function notifyCriticalStakeholders(\Throwable $e, array $context): void
    {
        foreach ($this->config['critical_stakeholders'] as $stakeholder) {
            $this->notifyStakeholder($stakeholder, $e, $context);
        }
    }

    protected function logException(\Throwable $e, array $context): void
    {
        $severity = $this->isCriticalException($e) ? 'critical' : 'error';

        Log::$severity($e->getMessage(), [
            'exception' => $e,
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleHandlerFailure(\Throwable $original, \Throwable $handler): void
    {
        Log::emergency('Error handler failure', [
            'original_exception' => $original,
            'handler_exception' => $handler
        ]);

        $this->metrics['handler_failures']++;
    }

    protected function handleEmergencyActionFailure(string $action, \Exception $e): void
    {
        Log::emergency("Emergency action failed: {$action}", [
            'action' => $action,
            'exception' => $e
        ]);

        $this->metrics['emergency_failures']++;
    }

    protected function initializeMetrics(): void
    {
        $this->metrics = [
            'exceptions' => [],
            'critical_exceptions' => 0,
            'validation_failures' => 0,
            'business_errors' => 0,
            'system_errors' => 0,
            'handler_failures' => 0,
            'emergency_failures' => 0,
            'last_exception' => null
        ];
    }
}
