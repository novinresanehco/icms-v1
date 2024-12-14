<?php

namespace App\Core\Metrics;

class MetricsValidator
{
    private ValidationService $validator;
    private SecurityManager $security;
    
    private const REQUIRED_METRICS = [
        'timestamp',
        'system',
        'performance',
        'security'
    ];
    
    private const REQUIRED_SYSTEM_METRICS = [
        'memory',
        'cpu',
        'connections'
    ];
    
    private const REQUIRED_PERFORMANCE_METRICS = [
        'response_time',
        'throughput',
        'error_rate'
    ];
    
    private const REQUIRED_SECURITY_METRICS = [
        'access_attempts',
        'validation_failures',
        'threat_level'
    ];

    public function __construct(
        ValidationService $validator,
        SecurityManager $security
    ) {
        $this->validator = $validator;
        $this->security = $security;
    }

    public function validateMetrics(array $metrics, array $requirements): bool
    {
        try {
            // Validate basic structure
            $this->validateStructure($metrics);
            
            // Validate specific requirements
            $this->validateRequirements($metrics, $requirements);
            
            // Validate metric values
            $this->validateValues($metrics);
            
            // Validate security constraints
            $this->validateSecurity($metrics);
            
            return true;
            
        } catch (ValidationException $e) {
            throw new MetricsValidationException(
                'Metrics validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function validateStructure(array $metrics): void
    {
        // Check required top-level metrics
        foreach (self::REQUIRED_METRICS as $required) {
            if (!isset($metrics[$required])) {
                throw new ValidationException("Missing required metric: {$required}");
            }
        }
        
        // Validate system metrics
        foreach (self::REQUIRED_SYSTEM_METRICS as $required) {
            if (!isset($metrics['system'][$required])) {
                throw new ValidationException("Missing required system metric: {$required}");
            }
        }
        
        // Validate performance metrics
        foreach (self::REQUIRED_PERFORMANCE_METRICS as $required) {
            if (!isset($metrics['performance'][$required])) {
                throw new ValidationException("Missing required performance metric: {$required}");
            }
        }
        
        // Validate security metrics
        foreach (self::REQUIRED_SECURITY_METRICS as $required) {
            if (!isset($metrics['security'][$required])) {
                throw new ValidationException("Missing required security metric: {$required}");
            }
        }
    }

    private function validateRequirements(array $metrics, array $requirements): void
    {
        foreach ($requirements as $requirement) {
            if (!$this->validateRequirement($metrics, $requirement)) {
                throw new ValidationException("Failed requirement: {$requirement['name']}");
            }
        }
    }

    private function validateValues(array $metrics): void
    {
        // Validate timestamp
        if (!is_numeric($metrics['timestamp']) || $metrics['timestamp'] <= 0) {
            throw new ValidationException('Invalid timestamp value');
        }
        
        // Validate system metrics
        $this->validateSystemMetrics($metrics['system']);
        
        // Validate performance metrics
        $this->validatePerformanceMetrics($metrics['performance']);
        
        // Validate security metrics
        $this->validateSecurityMetrics($metrics['security']);
    }

    private function validateSystemMetrics(array $systemMetrics): void
    {
        // Validate memory usage
        if (!is_numeric($systemMetrics['memory']) || $systemMetrics['memory'] < 0) {
            throw new ValidationException('Invalid memory usage value');
        }
        
        // Validate CPU metrics
        if (!is_array($systemMetrics['cpu']) || count($systemMetrics['cpu']) !== 3) {
            throw new ValidationException('Invalid CPU metrics format');
        }
        
        // Validate connection metrics
        if (!isset($systemMetrics['connections']['active']) || 
            !isset($systemMetrics['connections']['idle']) ||
            !isset($systemMetrics['connections']['total'])) {
            throw new ValidationException('Invalid connection metrics');
        }
    }

    private function validatePerformanceMetrics(array $performanceMetrics): void
    {
        // Validate response times
        $this->validateResponseTimeMetrics($performanceMetrics['response_time']);
        
        // Validate throughput
        $this->validateThroughputMetrics($performanceMetrics['throughput']);
        
        // Validate error rates
        $this->validateErrorRateMetrics($performanceMetrics['error_rate']);
    }

    private function validateSecurityMetrics(array $securityMetrics): void
    {
        // Validate through security manager
        if (!$this->security->validateMetrics($securityMetrics)) {
            throw new ValidationException('Security metrics validation failed');
        }
    }

    private function validateRequirement(array $metrics, array $requirement): bool
    {
        switch ($requirement['type']) {
            case 'threshold':
                return $this->validateThreshold($metrics, $requirement);
            case 'pattern':
                return $this->validatePattern($metrics, $requirement);
            case 'range':
                return $this->validateRange($metrics, $requirement);
            default:
                throw new ValidationException("Unknown requirement type: {$requirement['type']}");
        }
    }
}
