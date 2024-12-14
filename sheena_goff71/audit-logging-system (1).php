<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Queue};
use App\Core\Security\SecurityContext;
use App\Core\Services\{EncryptionService, ValidationService, StorageService};
use App\Core\Exceptions\{AuditException, SecurityException};

class AuditLogger implements AuditInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private StorageService $storage;
    private array $config;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        StorageService $storage
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->config = config('audit');
    }

    public function logSecurityEvent(string $event, array $data, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($event, $data, $context) {
            try {
                // Validate event data
                $this->validateSecurityEvent($event, $data);

                // Process sensitive data
                $processedData = $this->processSensitiveData($data);

                // Create audit entry
                $entry = $this->createAuditEntry('security', $event, $processedData, $context);

                // Store with encryption
                $this->storeSecureEntry($entry);

                // Queue real-time alerts if needed
                $this->queueSecurityAlerts($entry);

                // Update audit indexes
                $this->updateAuditIndexes($entry);

                return true;

            } catch (\Exception $e) {
                $this->handleAuditFailure($e, $event, $context);
                throw new AuditException('Security event logging failed: ' . $e->getMessage());
            }
        });
    }

    public function logSystemEvent(string $event, array $data, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($event, $data, $context) {
            try {
                // Validate system event
                $this->validateSystemEvent($event, $data);

                // Enrich event data
                $enrichedData = $this->enrichSystemData($data);

                // Create audit entry
                $entry = $this->createAuditEntry('system', $event, $enrichedData, $context);

                // Store entry
                $this->storeAuditEntry($entry);

                // Process event rules
                $this->processEventRules($entry);

                return true;

            } catch (\Exception $e) {
                $this->handleAuditFailure($e, $event, $context);
                throw new AuditException('System event logging failed: ' . $e->getMessage());
            }
        });
    }

    public function logAccessEvent(string $event, array $data, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($event, $data, $context) {
            try {
                // Validate access event
                $this->validateAccessEvent($event, $data);

                // Enhance with access context
                $enhancedData = $this->enhanceAccessContext($data, $context);

                // Create audit entry
                $entry = $this->createAuditEntry('access', $event, $enhancedData, $context);

                // Store securely
                $this->storeSecureEntry($entry);

                // Process access patterns
                $this->processAccessPatterns($entry);

                return true;

            } catch (\Exception $e) {
                $this->handleAuditFailure($e, $event, $context);
                throw new AuditException('Access event logging failed: ' . $e->getMessage());
            }
        });
    }

    public function query(array $criteria, SecurityContext $context): array
    {
        try {
            // Validate query criteria
            $this->validateQueryCriteria($criteria);

            // Verify query permissions
            $this->verifyQueryPermissions($context);

            // Execute secure query
            return $this->executeSecureQuery($criteria, $context);

        } catch (\Exception $e) {
            $this->handleQueryFailure($e, $criteria, $context);
            throw new AuditException('Audit query failed: ' . $e->getMessage());
        }
    }

    private function validateSecurityEvent(string $event, array $data): void
    {
        if (!$this->validator->validateSecurityEvent($event, $data)) {
            throw new SecurityException('Invalid security event data');
        }
    }

    private function processSensitiveData(array $data): array
    {
        $processed = [];
        foreach ($data as $key => $value) {
            $processed[$key] = $this->isSensitive($key) 
                ? $this->encryption->encrypt($value)
                : $value;
        }
        return $processed;
    }

    private function createAuditEntry(string $type, string $event, array $data, SecurityContext $context): AuditEntry
    {
        return new AuditEntry([
            'type' => $type,
            'event' => $event,
            'data' => $data,
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => now(),
            'context' => $this->captureContext($context)
        ]);
    }

    private function storeSecureEntry(AuditEntry $entry): void
    {
        // Encrypt sensitive fields
        $encrypted = $this->encryptSensitiveFields($entry);

        // Calculate integrity hash
        $hash = $this->calculateIntegrityHash($encrypted);

        // Store with metadata
        $this->storage->store($encrypted, [
            'hash' => $hash,
            'metadata' => $this->generateMetadata($entry)
        ]);
    }

    private function queueSecurityAlerts(AuditEntry $entry): void
    {
        if ($this->requiresAlert($entry)) {
            Queue::push(new ProcessSecurityAlert($entry));
        }
    }

    private function requiresAlert(AuditEntry $entry): bool
    {
        return in_array($entry->event, $this->config['alert_events']);
    }

    private function updateAuditIndexes(AuditEntry $entry): void
    {
        foreach ($this->config['indexes'] as $index) {
            $this->updateIndex($index, $entry);
        }
    }

    private function enrichSystemData(array $data): array
    {
        return array_merge($data, [
            'system_state' => $this->captureSystemState(),
            'performance_metrics' => $this->capturePerformanceMetrics()
        ]);
    }

    private function enhanceAccessContext(array $data, SecurityContext $context): array
    {
        return array_merge($data, [
            'session_id' => $context->getSessionId(),
            'permissions' => $context->getPermissions(),
            'access_level' => $context->getAccessLevel()
        ]);
    }

    private function processAccessPatterns(AuditEntry $entry): void
    {
        $analyzer = new AccessPatternAnalyzer($this->config['pattern_rules']);
        $analyzer->analyze($entry);
    }

    private function executeSecureQuery(array $criteria, SecurityContext $context): array
    {
        // Apply security filters
        $securedCriteria = $this->applySecurityFilters($criteria, $context);

        // Execute query
        $results = $this->storage->query($securedCriteria);

        // Decrypt sensitive data
        return $this->decryptQueryResults($results);
    }

    private function handleAuditFailure(\Exception $e, string $event, SecurityContext $context): void
    {
        // Log failure securely
        $this->logFailureSecurely($e, $event, $context);

        // Execute failure protocols
        $this->executeFailureProtocols($e, $event);
    }

    private function handleQueryFailure(\Exception $e, array $criteria, SecurityContext $context): void
    {
        $this->logFailureSecurely($e, 'audit_query', $context, [
            'criteria' => $criteria
        ]);
    }
}
