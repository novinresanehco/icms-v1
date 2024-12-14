<?php

namespace App\Core\Validation;

class MetricsValidationEngine implements ValidationInterface 
{
    private array $validationRules;
    private array $thresholds;
    private SecurityManager $security;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->validationRules = config('validation.rules');
        $this->thresholds = config('validation.thresholds');
    }

    public function validateMetrics(array $metrics, OperationType $type): ValidationResult 
    {
        DB::beginTransaction();
        
        try {
            $this->validateStructure($metrics);
            $this->validateThresholds($metrics);
            $this->validateSecurity($metrics);
            $this->validateIntegrity($metrics);
            $this->validateCompliance($metrics, $type);
            
            DB::commit();
            return new ValidationResult(true);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateStructure(array $metrics): void 
    {
        foreach ($this->validationRules['required_fields'] as $field) {
            if (!isset($metrics[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        foreach ($metrics as $key => $value) {
            if (!$this->validateDataType($value, $this->validationRules['types'][$key])) {
                throw new ValidationException("Invalid data type for: {$key}");
            }
        }
    }

    private function validateThresholds(array $metrics): void 
    {
        foreach ($this->thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && !$this->checkThreshold($metrics[$metric], $threshold)) {
                throw new ValidationException("Threshold violation for: {$metric}");
            }
        }
    }

    private function validateSecurity(array $metrics): void 
    {
        if (!$this->security->validateMetricsSecurity($metrics)) {
            throw new SecurityValidationException("Security validation failed");
        }
    }

    private function validateIntegrity(array $metrics): void 
    {
        $checksum = $this->calculateChecksum($metrics);
        if ($checksum !== $metrics['integrity_hash']) {
            throw new IntegrityException("Integrity check failed");
        }
    }

    private function validateCompliance(array $metrics, OperationType $type): void 
    {
        $complianceRules = $this->getComplianceRules($type);
        foreach ($complianceRules as $rule) {
            if (!$this->validateComplianceRule($metrics, $rule)) {
                throw new ComplianceException("Compliance violation: {$rule->getCode()}");
            }
        }
    }

    private function checkThreshold($value, array $threshold): bool 
    {
        return match ($threshold['type']) {
            'max' => $value <= $threshold['value'],
            'min' => $value >= $threshold['value'],
            'range' => $value >= $threshold['min'] && $value <= $threshold['max'],
            'enum' => in_array($value, $threshold['values']),
            default => false
        };
    }

    private function calculateChecksum(array $metrics): string 
    {
        return hash_hmac(
            'sha256',
            json_encode($metrics['data']),
            config('app.key')
        );
    }

    private function validateDataType($value, string $type): bool 
    {
        return match ($type) {
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'boolean' => is_bool($value),
            'timestamp' => $this->validateTimestamp($value),
            default => false
        };
    }

    private function validateTimestamp($value): bool 
    {
        return is_numeric($value) && 
               $value > strtotime('-1 day') && 
               $value <= time();
    }

    private function getComplianceRules(OperationType $type): array 
    {
        return DB::table('compliance_rules')
            ->where('operation_type', $type->getValue())
            ->where('active', true)
            ->get()
            ->map(function($rule) {
                return new ComplianceRule($rule);
            })
            ->toArray();
    }

    private function validateComplianceRule(array $metrics, ComplianceRule $rule): bool 
    {
        return $rule->validate($metrics);
    }
}
