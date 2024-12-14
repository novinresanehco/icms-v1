<?php

namespace App\Core\Services;

use App\Core\Interfaces\DataIntegrityInterface;
use App\Core\Security\EncryptionService;
use App\Core\Events\{DataCorruptionDetected, IntegrityViolationDetected};
use App\Core\Exceptions\{IntegrityException, ValidationException};
use Illuminate\Support\Facades\{DB, Cache, Hash, Event};

class DataIntegrityService implements DataIntegrityInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function verifyDataIntegrity(array $data, string $checksum): bool
    {
        $calculatedChecksum = $this->calculateChecksum($data);
        
        if ($calculatedChecksum !== $checksum) {
            $this->handleIntegrityViolation($data, $checksum, $calculatedChecksum);
            return false;
        }
        
        return $this->validateDataStructure($data) && 
               $this->validateBusinessRules($data);
    }

    public function protectData(array $data): array
    {
        DB::beginTransaction();
        
        try {
            $protectedData = $this->encryptSensitiveFields($data);
            $protectedData['_integrity'] = [
                'checksum' => $this->calculateChecksum($data),
                'timestamp' => microtime(true),
                'version' => $this->config['integrity_version']
            ];
            
            $this->audit->logDataProtection($data, $protectedData);
            
            DB::commit();
            return $protectedData;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new IntegrityException(
                'Data protection failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function monitorDataIntegrity(): void
    {
        $violations = [];
        
        foreach ($this->config['monitored_tables'] as $table) {
            $violations = array_merge(
                $violations,
                $this->checkTableIntegrity($table)
            );
        }
        
        if (!empty($violations)) {
            $this->handleIntegrityViolations($violations);
        }
    }

    public function validateTransactionIntegrity(
        string $transactionId,
        array $beforeState,
        array $afterState
    ): bool {
        try {
            // Verify transaction consistency
            if (!$this->verifyTransactionConsistency($transactionId, $beforeState, $afterState)) {
                throw new IntegrityException('Transaction consistency violation detected');
            }

            // Verify business rules
            if (!$this->validateBusinessRules($afterState)) {
                throw new ValidationException('Business rule validation failed');
            }

            // Verify referential integrity
            if (!$this->verifyReferentialIntegrity($afterState)) {
                throw new IntegrityException('Referential integrity violation detected');
            }

            return true;

        } catch (\Exception $e) {
            $this->handleTransactionViolation($transactionId, $beforeState, $afterState, $e);
            return false;
        }
    }

    protected function calculateChecksum(array $data): string
    {
        $normalized = $this->normalizeData($data);
        return Hash::make(json_encode($normalized));
    }

    protected function normalizeData(array $data): array
    {
        ksort($data);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeData($value);
            }
        }
        
        return $data;
    }

    protected function encryptSensitiveFields(array $data): array
    {
        foreach ($this->config['sensitive_fields'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->encryption->encrypt($data[$field]);
            }
        }
        
        return $data;
    }

    protected function validateDataStructure(array $data): bool
    {
        try {
            return $this->validator->validateStructure(
                $data,
                $this->config['structure_rules']
            );
        } catch (ValidationException $e) {
            $this->audit->logValidationFailure('structure', $data, $e);
            return false;
        }
    }

    protected function validateBusinessRules(array $data): bool
    {
        try {
            return $this->validator->validateBusinessRules(
                $data,
                $this->config['business_rules']
            );
        } catch (ValidationException $e) {
            $this->audit->logValidationFailure('business_rules', $data, $e);
            return false;
        }
    }

    protected function verifyReferentialIntegrity(array $data): bool
    {
        foreach ($this->config['integrity_constraints'] as $constraint) {
            if (!$this->checkConstraint($data, $constraint)) {
                return false;
            }
        }
        
        return true;
    }

    protected function checkTableIntegrity(string $table): array
    {
        $violations = [];
        $records = DB::table($table)->get();
        
        foreach ($records as $record) {
            if (!$this->verifyRecordIntegrity($record)) {
                $violations[] = [
                    'table' => $table,
                    'record_id' => $record->id,
                    'type' => 'data_corruption'
                ];
            }
        }
        
        return $violations;
    }

    protected function handleIntegrityViolation(
        array $data,
        string $expectedChecksum,
        string $actualChecksum
    ): void {
        $violation = [
            'data' => $data,
            'expected_checksum' => $expectedChecksum,
            'actual_checksum' => $actualChecksum,
            'timestamp' => microtime(true)
        ];
        
        $this->audit->logIntegrityViolation($violation);
        Event::dispatch(new IntegrityViolationDetected($violation));
    }

    protected function handleIntegrityViolations(array $violations): void
    {
        foreach ($violations as $violation) {
            Event::dispatch(new DataCorruptionDetected($violation));
        }
        
        $this->audit->logBulkViolations($violations);
        
        if ($this->shouldTriggerEmergencyProtocol($violations)) {
            $this->triggerEmergencyProtocol($violations);
        }
    }
}
