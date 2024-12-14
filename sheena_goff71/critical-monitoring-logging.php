<?php

namespace App\Core\Monitoring;

class CriticalMonitoringLogger implements MonitoringInterface
{
    private ValidatorRegistry $validators;
    private MetricsCollector $metrics;
    private LogManager $logManager;
    private AlertSystem $alertSystem;
    private StateManager $stateManager;

    public function logValidation(Operation $operation): LoggingResult
    {
        $loggingId = $this->logManager->startLogging();
        DB::beginTransaction();

        try {
            // Record initial state
            $this->recordInitialState($operation, $loggingId);

            // Log validation chain execution
            $validationResult = $this->logValidationChain($operation, $loggingId);

            // Record final state and metrics
            $this->recordFinalState($operation, $loggingId);

            DB::commit();
            return new LoggingResult(true);

        } catch (LoggingException $e) {
            DB::rollBack();
            $this->handleLoggingFailure($loggingId, $operation, $e);
            throw $e;
        }
    }

    private function logValidationChain(Operation $operation, string $loggingId): void
    {
        // Architecture validation logging
        $this->logValidationStage('ARCHITECTURE', function() use ($operation) {
            $result = $this->validators->architecture->validate($operation);
            if (!$result->isValid()) {
                $this->logViolation('ARCHITECTURE', $result->getViolations());
                throw new ArchitectureViolationException($result->getViolations());
            }
            return $result;
        });

        // Security validation logging
        $this->logValidationStage('SECURITY', function() use ($operation) {
            $result = $this->validators->security->validate($operation);
            if (!$result->isValid()) {
                $this->logViolation('SECURITY', $result->getViolations());
                throw new SecurityViolationException($result->getViolations());
            }
            return $result;
        });

        // Quality validation logging
        $this->logValidationStage('QUALITY', function() use ($operation) {
            $result = $this->validators->quality->validate($operation);
            if (!$result->isValid()) {
                $this->logViolation('QUALITY', $result->getViolations());
                throw new QualityViolationException($result->getViolations());
            }
            return $result;
        });

        // Performance validation logging
        $this->logValidationStage('PERFORMANCE', function() use ($operation) {
            $result = $this->validators->performance->validate($operation);
            if (!$result->isValid()) {
                $this->logViolation('PERFORMANCE', $result->getViolations());
                throw new PerformanceViolationException($result->getViolations());
            }
            return $result;
        });
    }

    private function logValidationStage(string $stage, callable $validation): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $result = $validation();

            $this->logManager->logStage([
                'stage' => $stage,
                'status' => 'SUCCESS',
                'duration' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'result' => $result
            ]);

        } catch (ValidationException $e) {
            $this->logManager->logStage([
                'stage' => $stage,
                'status' => 'FAILURE',
                'duration' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function recordInitialState(Operation $operation, string $loggingId): void
    {
        $this->stateManager->recordState($loggingId, 'INITIAL', [
            'timestamp' => now(),
            'operation' => $operation->getIdentifier(),
            'metrics' => $this->metrics->collectInitialMetrics(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function recordFinalState(Operation $operation, string $loggingId): void
    {
        $this->stateManager->recordState($loggingId, 'FINAL', [
            'timestamp' => now(),
            'operation' => $operation->getIdentifier(),
            'metrics' => $this->metrics->collectFinalMetrics(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'system' => [
                'load' => sys_getloadavg(),
                'connections' => DB::connection()->count()
            ],
            'metrics' => [
                'response_time' => $this->metrics->getAverageResponseTime(),
                'throughput' => $this->metrics->getCurrentThroughput(),
                'error_rate' => $this->metrics->getErrorRate()
            ],
            'resources' => [
                'cpu' => $this->metrics->getCpuUsage(),
                'io' => $this->metrics->getIoMetrics(),
                'network' => $this->metrics->getNetworkMetrics()
            ]
        ];
    }

    private function handleLoggingFailure(
        string $loggingId,
        Operation $operation,
        LoggingException $e
    ): void {
        $this->logManager->logFailure($loggingId, [
            'operation' => $operation->getIdentifier(),
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        $this->alertSystem->triggerAlert([
            'type' => 'LOGGING_FAILURE',
            'severity' => 'CRITICAL',
            'operation' => $operation->getIdentifier(),
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
    }
}
