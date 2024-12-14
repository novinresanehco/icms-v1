<?php

namespace App\Core\Data;

use App\Core\Security\EncryptionService;
use App\Core\Infrastructure\Monitoring;
use App\Core\Audit\AuditLogger;

class DataIntegrityService implements IntegrityInterface
{
    private EncryptionService $encryption;
    private Monitoring $monitor;
    private AuditLogger $audit;

    private const VALIDATION_TIMEOUT = 1000; // ms
    private const INTEGRITY_CHECK_INTERVAL = 30; // seconds
    private const MAX_RETRIES = 3;

    public function __construct(
        EncryptionService $encryption,
        Monitoring $monitor,
        AuditLogger $audit
    ) {
        $this->encryption = $encryption;
        $this->monitor = $monitor;
        $this->audit = $audit;
    }

    public function verifyDataIntegrity(CriticalData $data): IntegrityResult
    {
        $operationId = $this->monitor->startOperation();

        try {
            // Pre-verification checks
            $this->validateDataStructure($data);
            
            // Verify checksums
            $this->verifyChecksums($data);
            
            // Verify encryption
            $this->verifyEncryption($data);
            
            // Verify data consistency
            $this->verifyConsistency($data);
            
            // Record verification
            $this->recordVerification($data);
            
            return new IntegrityResult(true, $this->generateProof($data));

        } catch (\Exception $e) {
            $this->handleVerificationFailure($e, $data);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateDataStructure(CriticalData $data): void
    {
        if (!$data->hasRequiredFields()) {
            throw new StructureException('Missing required data fields');
        }

        if (!$data->isValidFormat()) {
            throw new FormatException('Invalid data format');
        }

        if (!$this->validateMetadata($data)) {
            throw new MetadataException('Invalid data metadata');
        }
    }

    private function verifyChecksums(CriticalData $data): void
    {
        $actualChecksum = $this->calculateChecksum($data);
        
        if (!hash_equals($data->getChecksum(), $actualChecksum)) {
            throw new ChecksumException('Data checksum verification failed');
        }

        if (!$this->verifyHistoricalChecksums($data)) {
            throw new IntegrityException('Historical checksum verification failed');
        }
    }

    private function verifyEncryption(CriticalData $data): void
    {
        if (!$this->encryption->verifyData($data)) {
            throw new EncryptionException('Data encryption verification failed');
        }

        if (!$this->encryption->verifySignature($data)) {
            throw new SignatureException('Data signature verification failed');
        }
    }

    private function verifyConsistency(CriticalData $data): void
    {
        if (!$this->verifyInternalConsistency($data)) {
            throw new ConsistencyException('Internal data consistency check failed');
        }

        if (!$this->verifyRelationalConsistency($data)) {
            throw new ConsistencyException('Relational consistency check failed');
        }
    }

    private function verifyInternalConsistency(CriticalData $data): bool
    {
        foreach ($data->getConsistencyRules() as $rule) {
            if (!$this->validateRule($rule, $data)) {
                return false;
            }
        }
        return true;
    }

    private function verifyRelationalConsistency(CriticalData $data): bool
    {
        foreach ($data->getRelations() as $relation) {
            if (!$this->validateRelation($relation, $data)) {
                return false;
            }
        }
        return true;
    }

    private function validateRule(ConsistencyRule $rule, CriticalData $data): bool
    {
        $result = $rule->validate($data);

        $this->audit->logRuleValidation(
            'consistency_rule_check',
            [
                'rule' => get_class($rule),
                'result' => $result,
                'data_id' => $data->getId()
            ]
        );

        return $result;
    }

    private function validateRelation(DataRelation $relation, CriticalData $data): bool
    {
        $result = $relation->validate($data);

        $this->audit->logRelationValidation(
            'relation_check',
            [
                'relation' => get_class($relation),
                'result' => $result,
                'data_id' => $data->getId()
            ]
        );

        return $result;
    }

    private function calculateChecksum(CriticalData $data): string
    {
        return hash('sha256', serialize($data->getChecksumData()));
    }

    private function verifyHistoricalChecksums(CriticalData $data): bool
    {
        foreach ($data->getHistoricalChecksums() as $timestamp => $checksum) {
            $calculatedChecksum = $this->calculateHistoricalChecksum($data, $timestamp);
            if (!hash_equals($checksum, $calculatedChecksum)) {
                return false;
            }
        }
        return true;
    }

    private function calculateHistoricalChecksum(CriticalData $data, int $timestamp): string
    {
        return hash('sha256', serialize([
            'data' => $data->getHistoricalData($timestamp),
            'timestamp' => $timestamp
        ]));
    }

    private function generateProof(CriticalData $data): string
    {
        return $this->encryption->generateProof([
            'data_id' => $data->getId(),
            'checksum' => $data->getChecksum(),
            'timestamp' => now()
        ]);
    }

    private function validateMetadata(CriticalData $data): bool
    {
        return $data->hasValidTimestamp() &&
               $data->hasValidVersion() &&
               $data->hasValidAuthor();
    }

    private function recordVerification(CriticalData $data): void
    {
        $this->audit->logVerification(
            'integrity_verification',
            [
                'data_id' => $data->getId(),
                'checksum' => $data->getChecksum(),
                'timestamp' => now()
            ]
        );

        $this->monitor->recordIntegrityCheck($data);
    }

    private function handleVerificationFailure(\Exception $e, CriticalData $data): void
    {
        $this->audit->logFailure(
            'integrity_verification_failed',
            [
                'data_id' => $data->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        $this->monitor->recordIntegrityFailure($data);

        if ($e instanceof SecurityException) {
            $this->encryption->handleSecurityFailure($e);
        }
    }
}
