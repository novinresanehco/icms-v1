<?php

namespace App\Core\Security\DataProtection;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\Interfaces\DataProtectionInterface;
use App\Core\Security\Events\IntegrityEvent;
use App\Core\Security\Exceptions\{ValidationException, IntegrityException};

class DataProtectionService implements DataProtectionInterface
{
    private ValidationEngine $validator;
    private IntegrityManager $integrity;
    private SecurityAudit $audit;
    private array $protectionConfig;

    public function __construct(
        ValidationEngine $validator,
        IntegrityManager $integrity,
        SecurityAudit $audit,
        array $protectionConfig
    ) {
        $this->validator = $validator;
        $this->integrity = $integrity;
        $this->audit = $audit;
        $this->protectionConfig = $protectionConfig;
    }

    public function processSecureOperation(array $data, string $operationType): OperationResult
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validateWithProtection($data, $operationType);
            $processed = $this->processSensitiveData($validated);
            $secured = $this->applySecurityControls($processed);
            
            $this->verifyIntegrity($secured);
            $this->audit->logSecureOperation($operationType, $secured);
            
            DB::commit();
            return new OperationResult(true, $secured);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleProtectionFailure($e, $data);
            throw $e;
        }
    }

    protected function validateWithProtection(array $data, string $type): array
    {
        $rules = $this->loadValidationRules($type);
        
        if (!$validatedData = $this->validator->validate($data, $rules)) {
            throw new ValidationException('Data validation failed');
        }

        if ($this->detectMaliciousContent($validatedData)) {
            throw new SecurityException('Malicious content detected');
        }

        return $validatedData;
    }

    protected function processSensitiveData(array $data): array
    {
        $processed = [];
        
        foreach ($data as $key => $value) {
            if ($this->isSensitiveField($key)) {
                $processed[$key] = $this->encryptField($value);
                continue;
            }

            if ($this->requiresSanitization($key)) {
                $processed[$key] = $this->sanitizeField($value);
                continue;
            }

            $processed[$key] = $value;
        }

        return $processed;
    }

    protected function applySecurityControls(array $data): array
    {
        foreach ($this->protectionConfig['security_controls'] as $control) {
            $data = $this->applyControl($data, $control);
        }

        $data['_integrity'] = $this->integrity->generateHash($data);
        $data['_processed_at'] = now();
        
        return $data;
    }

    protected function verifyIntegrity(array $data): void
    {
        if (!$this->integrity->verifyHash($data)) {
            $this->audit->logIntegrityFailure($data);
            throw new IntegrityException('Data integrity verification failed');
        }
    }

    protected function loadValidationRules(string $type): array
    {
        $rules = $this->protectionConfig['validation_rules'][$type] ?? [];
        
        if (empty($rules)) {
            throw new ConfigurationException("No validation rules found for: $type");
        }

        return $rules;
    }

    protected function detectMaliciousContent(array $data): bool
    {
        foreach ($this->protectionConfig['malicious_patterns'] as $pattern) {
            if ($this->patternExists($data, $pattern)) {
                $this->audit->logMaliciousContent($pattern, $data);
                return true;
            }
        }
        
        return false;
    }

    protected function isSensitiveField(string $field): bool
    {
        return in_array($field, $this->protectionConfig['sensitive_fields']);
    }

    protected function requiresSanitization(string $field): bool
    {
        return in_array($field, $this->protectionConfig['sanitize_fields']);
    }

    protected function encryptField($value): string
    {
        return $this->integrity->encrypt($value);
    }

    protected function sanitizeField($value): string
    {
        return strip_tags(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }

    protected function applyControl(array $data, array $control): array
    {
        switch ($control['type']) {
            case 'encryption':
                return $this->applyEncryption($data, $control);
            case 'masking':
                return $this->applyMasking($data, $control);
            case 'hashing':
                return $this->applyHashing($data, $control);
            default:
                throw new ConfigurationException("Unknown security control: {$control['type']}");
        }
    }

    protected function handleProtectionFailure(\Exception $e, array $context): void
    {
        $this->audit->logProtectionFailure($e, $context);

        if ($e instanceof IntegrityException) {
            event(new IntegrityEvent($e, $context));
        }

        if ($this->isCriticalFailure($e)) {
            $this->triggerEmergencyProtocol($e);
        }
    }

    private function isCriticalFailure(\Exception $e): bool
    {
        return in_array($e->getCode(), $this->protectionConfig['critical_error_codes']);
    }

    private function triggerEmergencyProtocol(\Exception $e): void
    {
        $this->audit->logEmergency($e);
        Cache::tags(['security', 'protection'])->flush();
    }
}
