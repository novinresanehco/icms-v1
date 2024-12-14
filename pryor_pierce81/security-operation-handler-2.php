<?php

namespace App\Core\Security\Operations;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringInterface;
use App\Core\Validation\ValidationService;
use App\Core\Exception\SecurityOperationException;
use Psr\Log\LoggerInterface;

class SecurityOperationHandler implements SecurityOperationInterface
{
    private SecurityManagerInterface $security;
    private MonitoringInterface $monitor;
    private ValidationService $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringInterface $monitor,
        ValidationService $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function executeOperation(SecurityOperation $operation): OperationResult
    {
        $operationId = $this->generateOperationId();
        $monitoringId = $this->monitor->startOperation($operation);

        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            $this->security->validateContext($operation->getContext());
            
            // Execute with protection
            $result = $this->executeWithProtection($operation);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            // Log success
            $this->logSuccess($operationId, $operation, $result);
            
            return $result;

        } catch (\Exception $e) {
            $this->handleFailure($operationId, $operation, $e);
            throw new SecurityOperationException('Operation execution failed', 0, $e);
        } finally {
            $this->monitor->stopOperation($monitoringId);
        }
    }

    private function executeWithProtection(SecurityOperation $operation): OperationResult
    {
        return DB::transaction(function() use ($operation) {
            // Create secure execution context
            $context = $this->createSecureContext($operation);
            
            // Execute with timeout
            return $this->executeWithTimeout(
                fn() => $operation->execute($context),
                $this->config['operation_timeout']
            );
        });
    }

    private function createSecureContext(SecurityOperation $operation): SecurityContext
    {
        return new SecurityContext(
            $operation->getContext(),
            $this->security->getCurrentUser(),
            $this->generateContextId()
        );
    }

    private function executeWithTimeout(callable $callback, int $timeout): mixed
    {
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new SecurityOperationException('Could not fork process');
        }

        if ($pid) {
            // Parent process
            $status = null;
            $waited = 0;
            $interval = 100000; // 0.1 second

            while ($waited < $timeout) {
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res == -1 || $res > 0) break;
                usleep($interval);
                $waited += $interval;
            }

            if ($waited >= $timeout) {
                posix_kill($pid, SIGTERM);
                throw new SecurityOperationException('Operation timed out');
            }

            return $status === 0;
        } else {
            // Child process
            try {
                $result = $callback();
                exit(0);
            } catch (\Exception $e) {
                exit(1);
            }
        }
    }

    private function validateOperation(SecurityOperation $operation): void
    {
        $violations = $this->validator->validate($operation, [
            'context' => 'required|array',
            'type' => 'required|string|max:64',
            'parameters' => 'required|array'
        ]);

        if (count($violations) > 0) {
            throw new SecurityOperationException('Invalid operation structure');
        }

        if (!$this->security->isOperationAllowed($operation->getType())) {
            throw new SecurityOperationException('Operation type not allowed');
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new SecurityOperationException('Operation result validation failed');
        }

        if ($result->hasWarnings() && !$this->config['allow_warnings']) {
            throw new SecurityOperationException('Operation produced warnings');
        }
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function generateContextId(): string
    {
        return uniqid('ctx_', true);
    }

    private function logSuccess(
        string $operationId,
        SecurityOperation $operation,
        OperationResult $result
    ): void {
        $this->logger->info('Security operation completed successfully', [
            'operation_id' => $operationId,
            'type' => $operation->getType(),
            'result' => $result->toArray()
        ]);
    }

    private function handleFailure(
        string $operationId,
        SecurityOperation $operation,
        \Exception $e
    ): void {
        $this->logger->error('Security operation failed', [
            'operation_id' => $operationId,
            'type' => $operation->getType(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->logSecurityEvent(
            'operation_failed',
            [
                'operation_id' => $operationId,
                'type' => $operation->getType(),
                'error' => $e->getMessage()
            ]
        );
    }

    private function getDefaultConfig(): array
    {
        return [
            'operation_timeout' => 30,
            'allow_warnings' => false,
            'max_retries' => 3,
            'retry_delay' => 1
        ];
    }
}
