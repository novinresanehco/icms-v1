<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Log;
use App\Core\Contracts\ValidatorInterface;
use App\Core\Exceptions\{ValidationException, IntegrityException};

class MetricsValidator implements ValidatorInterface 
{
    private SecurityManager $security;
    private IntegrityChecker $integrity;
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;

    public function __construct(
        SecurityManager $security,
        IntegrityChecker $integrity,
        ThresholdManager $thresholds,
        AlertSystem $alerts
    ) {
        $this->security = $security;
        $this->integrity = $integrity;
        $this->thresholds = $thresholds;
        $this->alerts = $alerts;
    }

    public function validateMetrics(array $metrics, array $requirements): bool
    {
        DB::beginTransaction();

        try {
            // Structure validation
            $this->validateStructure($metrics);

            // Data integrity check
            $this->validateIntegrity($metrics);

            // Security validation
            $this->validateSecurity($metrics);

            // Requirements validation
            $this->validateRequirements($metrics, $requirements);

            // Threshold validation
            $this->validateThresholds($metrics);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $metrics);
            throw $e;
        }
    }

    private function validateStructure(array $metrics): void
    {
        $required = ['timestamp', 'operation', 'system', 'performance', 'security'];

        foreach ($required as $field) {
            if (!isset($metrics[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        if (!$this->validateMetricsFormat($metrics)) {
            throw new ValidationException('Invalid metrics format');
        }
    }

    private function validateIntegrity(array $metrics): void
    {
        if (!$this->integrity->verifyChecksum($metrics)) {
            throw new IntegrityException('Metrics integrity check failed');
        }

        if (!$this->integrity->verifySequence($metrics)) {
            throw new IntegrityException('Metrics sequence validation failed');
        }
    }

    private function validateSecurity(array $metrics): void
    {
        if (!$this->security->validateAccess($metrics)) {
            throw new SecurityException('Security validation failed');
        }

        if (!$this->security->validateEncryption($metrics)) {
            throw new SecurityException('Encryption validation failed');
        }
    }

    private function validateRequirements(array $metrics, array $requirements): void
    {
        foreach ($requirements as $requirement) {
            if (!$this->validateRequirement($metrics, $requirement)) {
                throw new ValidationException(
                    "Failed requirement: {$requirement->getName()}"
                );
            }
        }
    }

    private function validateThresholds(array $metrics): void
    {
        foreach ($metrics['performance'] as $metric => $value) {
            $threshold = $this->thresholds->getThreshold($metric);
            
            if ($threshold && !$this->validateThreshold($value, $threshold)) {
                $this->handleThresholdViolation($metric, $value, $threshold);
            }
        }
    }

    private function validateMetricsFormat(array $metrics): bool
    {
        return $this->validateSystemMetrics($metrics['system']) &&
               $this->validatePerformanceMetrics($metrics['performance']) &&
               $this->validateSecurityMetrics($metrics['security']);
    }

    private function validateSystemMetrics(array $metrics): bool
    {
        return isset($metrics['memory']) &&
               isset($metrics['cpu']) &&
               isset($metrics['connections']) &&
               is_array($metrics['connections']);
    }

    private function validatePerformanceMetrics(array $metrics): bool
    {
        return isset($metrics['response_time']) &&
               isset($metrics['throughput']) &&
               isset($metrics['error_rate']) &&
               is_array($metrics['response_time']);
    }

    private function validateSecurityMetrics(array $metrics): bool
    {
        return isset($metrics['access_attempts']) &&
               isset($metrics['validation_failures']) &&
               isset($metrics['threat_level']);
    }

    private function validateRequirement(array $metrics, Requirement $requirement): bool
    {
        try {
            return $requirement->validate($metrics);
        } catch (\Exception $e) {
            Log::warning('Requirement validation failed', [
                'requirement' => $requirement->getName(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function validateThreshold($value, Threshold $threshold): bool
    {
        return $value <= $threshold->getMaxValue() &&
               $value >= $threshold->getMinValue();
    }

    private function handleThresholdViolation(string $metric, $value, Threshold $threshold): void
    {
        $this->alerts->sendThresholdAlert([
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold->toArray(),
            'timestamp' => now()
        ]);

        if ($threshold->isCritical()) {
            throw new ValidationException(
                "Critical threshold violation for metric: {$metric}"
            );
        }
    }

    private function handleValidationFailure(\Exception $e, array $metrics): void
    {
        Log::error('Metrics validation failed', [
            'error' => $e->getMessage(),
            'metrics' => $metrics,
            'trace' => $e->getTraceAsString()
        ]);

        $this->alerts->sendValidationAlert([
            'error' => $e->getMessage(),
            'metrics' => $metrics,
            'timestamp' => now()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleValidationFailure($e);
        }
    }
}
