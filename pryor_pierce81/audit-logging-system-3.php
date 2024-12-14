<?php

namespace App\Core\Audit;

class AuditManager implements AuditManagerInterface 
{
    private Repository $repository;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private StorageManager $storage;
    private MetricsCollector $metrics;

    public function logSecurityEvent(array $data): void 
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validator->validate($data, [
                'event_type' => 'required|string',
                'severity' => 'required|in:low,medium,high,critical',
                'details' => 'required|array',
                'user_id' => 'required|integer'
            ]);

            $logEntry = $this->repository->create([
                'type' => 'security',
                'event_type' => $validated['event_type'],
                'severity' => $validated['severity'],
                'details' => $this->encryption->encrypt(json_encode($validated['details'])),
                'user_id' => $validated['user_id'],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now(),
                'hash' => $this->generateEntryHash($validated)
            ]);

            $this->metrics->incrementSecurityEvent($validated['event_type']);
            
            if ($validated['severity'] === 'critical') {
                $this->notifySecurity($logEntry);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuditLoggingException('Failed to log security event', 0, $e);
        }
    }

    public function logAccessEvent(array $data): void 
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validator->validate($data, [
                'resource_type' => 'required|string',
                'resource_id' => 'required',
                'action' => 'required|string',
                'status' => 'required|in:success,failure',
                'user_id' => 'required|integer'
            ]);

            $this->repository->create([
                'type' => 'access',
                'resource_type' => $validated['resource_type'],
                'resource_id' => $validated['resource_id'],
                'action' => $validated['action'],
                'status' => $validated['status'],
                'user_id' => $validated['user_id'],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now(),
                'hash' => $this->generateEntryHash($validated)
            ]);

            $this->metrics->incrementAccessEvent($validated['action'], $validated['status']);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuditLoggingException('Failed to log access event', 0, $e);
        }
    }

    public function logDataEvent(array $data): void 
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validator->validate($data, [
                'data_type' => 'required|string',
                'operation' => 'required|string',
                'entity_id' => 'required',
                'changes' => 'required|array',
                'user_id' => 'required|integer'
            ]);

            $this->repository->create([
                'type' => 'data',
                'data_type' => $validated['data_type'],
                'operation' => $validated['operation'],
                'entity_id' => $validated['entity_id'],
                'changes' => $this->encryption->encrypt(json_encode($validated['changes'])),
                'user_id' => $validated['user_id'],
                'ip_address' => request()->ip(),
                'timestamp' => now(),
                'hash' => $this->generateEntryHash($validated)
            ]);

            $this->metrics->incrementDataEvent($validated['operation']);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuditLoggingException('Failed to log data event', 0, $e);
        }
    }

    public function getAuditLog(array $filters = []): Collection 
    {
        try {
            $validated = $this->validator->validate($filters, [
                'type' => 'string|in:security,access,data',
                'start_date' => 'date',
                'end_date' => 'date',
                'user_id' => 'integer',
                'severity' => 'string|in:low,medium,high,critical'
            ]);

            return $this->repository->getFiltered($validated);

        } catch (\Exception $e) {
            throw new AuditLoggingException('Failed to retrieve audit log', 0, $e);
        }
    }

    public function exportAuditLog(array $filters = []): string 
    {
        try {
            $logs = $this->getAuditLog($filters);
            
            $exportData = $logs->map(function ($log) {
                $log->details = $this->encryption->decrypt($log->details);
                return $log;
            });

            $filename = 'audit_log_' . now()->format('Y-m-d_H-i-s') . '.json';
            
            $this->storage->store(
                $filename,
                json_encode($exportData, JSON_PRETTY_PRINT),
                ['encrypt' => true]
            );

            return $filename;

        } catch (\Exception $e) {
            throw new AuditLoggingException('Failed to export audit log', 0, $e);
        }
    }

    private function generateEntryHash(array $data): string 
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            config('app.key')
        );
    }

    private function notifySecurity(AuditLog $logEntry): void 
    {
        // Implementation for security notification
    }
}
