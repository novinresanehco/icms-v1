<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Storage\StorageManager;
use App\Core\Exceptions\AuditException;

class AuditManager implements AuditInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private StorageManager $storage;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        StorageManager $storage,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function logActivity(string $type, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('audit_logging');
        
        try {
            $this->validateActivityData($type, $data);
            
            $entry = $this->prepareAuditEntry($type, $data);
            $this->storeAuditEntry($entry);
            
            if ($this->isHighPriorityAudit($type)) {
                $this->processHighPriorityAudit($entry);
            }
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new AuditException('Audit logging failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function generateAuditReport(array $criteria): AuditReport
    {
        $monitoringId = $this->monitor->startOperation('audit_report');
        
        try {
            $this->validateReportCriteria($criteria);
            
            $entries = $this->fetchAuditEntries($criteria);
            $report = $this->generateReport($entries, $criteria);
            
            $this->validateReport($report);
            $this->storeReport($report);
            
            $this->monitor->recordSuccess($monitoringId);
            return $report;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new AuditException('Audit report generation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateActivityData(string $type, array $data): void
    {
        if (!in_array($type, $this->config['allowed_types'])) {
            throw new AuditException('Invalid audit type');
        }

        $requiredFields = $this->config['required_fields'][$type] ?? [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new AuditException("Missing required field: {$field}");
            }
        }

        if (!$this->validateDataSecurity($data)) {
            throw new AuditException('Data security validation failed');
        }
    }

    private function prepareAuditEntry(string $type, array $data): array
    {
        return [
            'type' => $type,
            'data' => $this->sanitizeData($data),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now(),
            'hash' => $this->generateEntryHash($type, $data),
            'metadata' => $this->collectMetadata()
        ];
    }

    private function storeAuditEntry(array $entry): void
    {
        DB::transaction(function() use ($entry) {
            AuditLog::create($entry);
            
            if ($this->config['redundant_storage']) {
                $this->storeRedundantCopy($entry);
            }
        });
    }

    private function isHighPriorityAudit(string $type): bool
    {
        return in_array($type, $this->config['high_priority_types']);
    }

    private function processHighPriorityAudit(array $entry): void
    {
        $this->notifySecurityTeam($entry);
        $this->createSecurityEvent($entry);
        $this->updateSecurityMetrics($entry);
    }

    private function validateReportCriteria(array $criteria): void
    {
        $allowedCriteria = $this->config['allowed_criteria'];
        
        foreach ($criteria as $key => $value) {
            if (!in_array($key, $allowedCriteria)) {
                throw new AuditException("Invalid report criteria: {$key}");
            }
        }

        if (isset($criteria['date_range'])) {
            $this->validateDateRange($criteria['date_range']);
        }
    }

    private function fetchAuditEntries(array $criteria): array
    {
        $query = AuditLog::query();
        
        foreach ($criteria as $key => $value) {
            $query = $this->applyQueryCriteria($query, $key, $value);
        }
        
        return $query->get()->toArray();
    }

    private function generateReport(array $entries, array $criteria): AuditReport
    {
        $report = new AuditReport();
        
        $report->setCriteria($criteria);
        $report->setEntries($entries);
        $report->setMetadata($this->collectReportMetadata());
        $report->analyze();
        
        return $report;
    }

    private function validateReport(AuditReport $report): void
    {
        if (!$report->validate()) {
            throw new AuditException('Report validation failed');
        }

        if (!$this->validateReportSecurity($report)) {
            throw new AuditException('Report security validation failed');
        }
    }

    private function storeReport(AuditReport $report): void
    {
        $path = $this->getReportStoragePath($report);
        
        $this->storage->store(
            $path,
            $report->serialize(),
            ['encrypt' => true]
        );
    }

    private function validateDataSecurity(array $data): bool
    {
        return $this->security->validateAuditData($data);
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if ($this->shouldMaskField($key)) {
                $sanitized[$key] = $this->maskSensitiveData($value);
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        
        return $sanitized;
    }

    private function generateEntryHash(string $type, array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode([$type, $data]),
            $this->config['hash_key']
        );
    }

    private function collectMetadata(): array
    {
        return [
            'environment' => app()->environment(),
            'version' => config('app.version'),
            'server' => request()->server(),
            'system_status' => $this->monitor->get