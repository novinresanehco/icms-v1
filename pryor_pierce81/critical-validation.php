<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Monitor\SystemMonitor;
use Illuminate\Support\Facades\{DB, Log};

final class ValidationManager
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private StateValidator $state;
    private array $rules;

    public function validateOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->monitor->startOperation();
        
        try {
            $this->preValidate($context);
            $result = $this->executeValidated($operation);
            $this->postValidate($result);
            
            $this->monitor->recordSuccess($operationId);
            return $result;
        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, $operationId);
            throw $e;
        }
    }

    private function preValidate(array $context): void
    {
        if (!$this->state->isValid()) {
            throw new StateException('Invalid system state');
        }

        if (!$this->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        $this->security->validateAccess($context);
    }

    private function executeValidated(callable $operation): mixed
    {
        return DB::transaction(function() use ($operation) {
            $this->state->checkpoint();
            $result = $operation();
            $this->state->verify();
            return $result;
        });
    }

    private function validateContext(array $context): bool
    {
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($context[$field] ?? null, $rule)) {
                return false;
            }
        }
        return true;
    }

    private function handleValidationFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->recordFailure($operationId, [
            'error' => $e->getMessage(),
            'context' => $this->state->capture(),
            'timestamp' => microtime(true)
        ]);

        Log::critical('Validation failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

final class StateValidator
{
    private MetricsCollector $metrics;
    private array $thresholds;

    public function isValid(): bool
    {
        $metrics = $this->metrics->collect();
        
        return !($metrics['memory'] > $this->thresholds['memory'] ||
                $metrics['cpu'] > $this->thresholds['cpu'] ||
                $metrics['errors'] > $this->thresholds['errors']);
    }

    public function checkpoint(): void
    {
        DB::beginTransaction();
        $this->metrics->snapshot();
    }

    public function verify(): void
    {
        if (!$this->isValid()) {
            DB::rollBack();
            throw new StateException('System state validation failed');
        }
    }

    public function capture(): array
    {
        return [
            'metrics' => $this->metrics->collect(),
            'timestamp' => microtime(true)
        ];
    }
}

final class MetricsCollector
{
    private array $snapshots = [];

    public function collect(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'errors' => $this->getErrorCount(),
            'queries' => count(DB::getQueryLog())
        ];
    }

    public function snapshot(): void
    {
        $this->snapshots[] = $this->collect();
    }

    private function getErrorCount(): int
    {
        return count(Log::getLogger()->getHandlers()[0]->getRecords());
    }
}

class ValidationException extends \Exception {}
class StateException extends \Exception {}
