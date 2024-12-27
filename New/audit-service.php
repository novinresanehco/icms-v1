<?php

namespace App\Core\Security;

use App\Core\Interfaces\AuditInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class AuditService implements AuditInterface
{
    private const CRITICAL_EVENTS = [
        'encryption_failure',
        'decryption_failure',
        'key_rotation_failure',
        'authentication_failure',
        'authorization_failure',
        'integrity_violation'
    ];

    public function logSecurityEvent(string $event, array $data = []): void
    {
        $isCritical = in_array($event, self::CRITICAL_EVENTS);
        
        try {
            DB::beginTransaction();

            // Record to database
            DB::table('security_audit_log')->insert([
                'event' => $event,
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'data' => json_encode($data),
                'severity' => $isCritical ? 'critical' : 'info',
                'created_at' => now()
            ]);

            // Also log critical events to system log
            if ($isCritical) {
                Log::critical("Security Event: {$event}", [
                    'user_id' => Auth::id(),
                    'ip' => request()->ip(),
                    'data' => $data
                ]);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Emergency logging if database insert fails
            Log::emergency('Failed to log security event', [
                'event' => $event,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    public function logAccessEvent(string $resource, string $action, bool $success): void
    {
        try {
            DB::beginTransaction();

            DB::table('access_audit_log')->insert([
                'user_id' => Auth::id(),
                'resource' => $resource,
                'action' => $action,
                'success' => $success,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);

            if (!$success) {
                Log::warning("Access Denied: {$resource}", [
                    'user_id' => Auth::id(),
                    'action' => $action,
                    'ip' => request()->ip()
                ]);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::emergency('Failed to log access event', [
                'resource' => $resource,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function getSecurityEvents(array $filters = []): array
    {
        $query = DB::table('security_audit_log')
            ->orderBy('created_at', 'desc');

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (isset($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        return $query->get()->toArray();
    }

    public function getAccessEvents(array $filters = []): array
    {
        $query = DB::table('access_audit_log')
            ->orderBy('created_at', 'desc');

        if (isset($filters['resource'])) {
            $query->where('resource', $filters['resource']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['success'])) {
            $query->where('success', $filters['success']);
        }

        return $query->get()->toArray();
    }

    public function pruneOldRecords(int $daysToKeep = 90): void
    {
        $cutoffDate = now()->subDays($daysToKeep);

        DB::beginTransaction();
        
        try {
            // Archive old records if needed
            $this->archiveOldRecords($cutoffDate);

            // Delete old records
            DB::table('security_audit_log')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            DB::table('access_audit_log')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to prune audit logs', [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function archiveOldRecords(\DateTime $cutoffDate): void
    {
        // Implementation depends on archival requirements
        // Could move to cold storage, export to files, etc.
    }
}
