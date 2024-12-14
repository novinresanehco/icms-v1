<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\AuditLoggerInterface;
use App\Core\Security\SecurityContext;
use Carbon\Carbon;

class AuditLogger implements AuditLoggerInterface
{
    private string $systemId;
    private array $config;

    public function __construct(string $systemId, array $config)
    {
        $this->systemId = $systemId;
        $this->config = $config;
    }

    public function logOperationStart(string $operationId, array $context): void
    {
        $this->logAuditEvent('OPERATION_START', $operationId, $context);
    }

    public function logOperationSuccess(string $operationId, array $details): void
    {
        $this->logAuditEvent('OPERATION_SUCCESS', $operationId, $details);
    }

    public function logOperationFailure(string $operationId, array $error): void
    {
        $this->logAuditEvent('OPERATION_FAILURE', $operationId, $error);
    }

    public function logSecurityEvent(string $type, array $details): void
    {
        $eventId = uniqid('sec_', true);
        $this->logAuditEvent($type, $eventId, $details, true);
    }

    private function logAuditEvent(
        string $type, 
        string $eventId, 
        array $details, 
        bool $isSecurityEvent = false
    ): void {
        try {
            $timestamp = Carbon::now();
            
            $logEntry = [
                'event_id' => $eventId,
                'system_id' => $this->systemId,
                'event_type' => $type,
                'timestamp' => $timestamp,
                'details' => json_encode($details),
                'user_id' => $this->getCurrentUserId(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'is_security_event' => $isSecurityEvent
            ];

            // Store in database
            DB::table('audit_logs')->insert($logEntry);

            // Critical events also go to system log
            if ($isSecurityEvent || $this->isCriticalEvent($type)) {
                Log::critical('Security Event', $logEntry);
            }

            // Archive if configured
            if ($this->config['enable_archive'] ?? false) {
                $this->archiveAuditEvent($logEntry);
            }

        } catch (\Exception $e) {
            // Last resort logging
            Log::emergency('Audit logging failed', [
                'error' => $e->getMessage(),
                'event' => compact('type', 'eventId', 'details')
            ]);
        }
    }

    private function getCurrentUserId(): ?int
    {
        try {
            return auth()->id();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function isCriticalEvent(string $type): bool
    {
        return in_array($type, $this->config['critical_events'] ?? []);
    }

    private function archiveAuditEvent(array $logEntry): void
    {
        try {
            $archivePath = storage_path('audit_archive');
            
            if (!file_exists($archivePath)) {
                mkdir($archivePath, 0755, true);
            }

            $filename = date('Y-m-d') . '_audit.log';
            $logLine = json_encode($logEntry) . "\n";

            file_put_contents(
                $archivePath . '/' . $filename,
                $logLine,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Exception $e) {
            Log::error('Audit archive failed', [
                'error' => $e->getMessage(),
                'entry' => $logEntry
            ]);
        }
    }
}
