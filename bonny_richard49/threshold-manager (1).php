<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ThresholdManager
{
    private MetricsStore $store;
    private AlertService $alerts;
    private SecurityManager $security;

    private const CACHE_PREFIX = 'threshold_';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        MetricsStore $store,
        AlertService $alerts,
        SecurityManager $security
    ) {
        $this->store = $store;
        $this->alerts = $alerts;
        $this->security = $security;
    }

    public function checkThresholds(array $metrics): ThresholdResult
    {
        DB::beginTransaction();
        
        try {
            // Load threshold configurations
            $thresholds = $this->loadThresholds();
            
            // Validate against thresholds
            $violations = $this->validateThresholds($metrics, $thresholds);
            
            // Process any violations
            if (!empty($violations)) {
                $this->processViolations($violations, $metrics);
            }
            
            // Update threshold statistics
            $this->updateThresholdStats($metrics, $thresholds);
            
            DB::commit();
            
            return new ThresholdResult(
                violations: $violations,
                metrics: $metrics,
                thresholds: $thresholds
            );
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ThresholdException('Failed to check thresholds: ' . $e->getMessage(), $e);
        }
    }

    private function loadThresholds(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'config';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() {
            return $this->store->getThresholds();
        });
    }

    private function validateThresholds(array $metrics, array $thresholds): array
    {
        $violations = [];

        foreach ($thresholds as $key => $threshold) {
            $value = $this->getMetricValue($metrics, $threshold['path']);
            
            if ($this->isThresholdViolated($value, $threshold)) {
                $violations[] = [
                    'threshold' => $key,
                    'value' => $value,
                    'limit' => $threshold['limit'],
                    'type' => $threshold['type'],
                    'severity' => $threshold['severity']
                ];
            }
        }

        return $violations;
    }

    private function isThresholdViolated($value, array $threshold): bool
    {
        switch ($threshold['type']) {
            case 'max':
                return $value > $threshold['limit'];
            case 'min':
                return $value < $threshold['limit'];
            case 'equals':
                return $value === $threshold['limit'];
            case 'not_equals':
                return $value !== $threshold['limit'];
            case 'range':
                return $value < $threshold['min'] || $value > $threshold['max'];
            default:
                throw new ThresholdException("Unknown threshold type: {$threshold['type']}");
        }
    }

    private function processViolations(array $violations, array $metrics): void
    {
        foreach ($violations as $violation) {
            // Log violation
            $this->logViolation($violation, $metrics);
            
            // Send alerts based on severity
            $this->sendAlerts($violation);
            
            // Execute automated responses
            $this->executeResponses($violation);
        }
    }

    private function logViolation(array $violation, array $metrics): void
    {
        $this->store->logViolation([
            'timestamp' => microtime(true),
            'violation' => $violation,
            'metrics' => $metrics,
            'context' => $this->security->getSecurityContext()
        ]);
    }

    private function sendAlerts(array $violation): void
    {
        switch ($violation['severity']) {
            case 'critical':
                $this->alerts->sendCriticalAlert($violation);
                break;
            case 'warning':
                $this->alerts->sendWarningAlert($violation);
                break;
            case 'info':
                $this->alerts->sendInfoAlert($violation);
                break;
        }
    }

    private function executeResponses(array $violation): void
    {
        // Execute automated response actions based on violation type
        switch ($violation['threshold']) {
            case 'cpu_usage':
                $this->executeResourceResponse($violation);
                break;
            case 'error_rate':
                $this->executeErrorResponse($violation);
                break;
            case 'security_threat':
                $this->executeSecurityResponse($violation);
                break;
        }
    }

    private function updateThresholdStats(array $metrics, array $thresholds): void
    {
        foreach ($thresholds as $key => $threshold) {
            $value = $this->getMetricValue($metrics, $threshold['path']);
            
            $this->store->updateThresholdStats($key, [
                'last_value' => $value,
                'last_check' => microtime(true),
                'check_count' => DB::raw('check_count + 1'),
                'violation_count' => DB::raw('violation_count + ' . 
                    ($this->isThresholdViolated($value, $threshold) ? '1' : '0'))
            ]);
        }
    }

    private function getMetricValue(array $metrics, string $path): mixed
    {
        $parts = explode('.', $path);
        $value = $metrics;
        
        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                throw new ThresholdException("Invalid metric path: {$path}");
            }
            $value = $value[$part];
        }
        
        return $value;
    }
}
