<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Log};
use App\Core\Security\SecurityManager;

class AuditService
{
    protected SecurityManager $security;
    protected array $criticalEvents = [
        'auth.login_failed',
        'auth.account_locked',
        'security.breach_detected',
        'data.corruption',
        'system.critical_error'
    ];

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
    }

    public function log(string $event, array $data): void
    {
        DB::transaction(function() use ($event, $data) {
            $logEntry = $this->createLogEntry($event, $data);
            
            $this->storeLog($logEntry);
            
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($logEntry);
            }
            
            $this->security->validateAuditLog($logEntry);
        });
    }

    protected function createLogEntry(string $event, array $data): array
    {
        return [
            'event' => $event,
            'data' => $data,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now(),
            'session_id' => session()->getId(),
            'request_id' => request()->id(),
            'context' => [
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'user_agent' => request()->userAgent(),
                'referrer' => request()->header('referer')
            ]
        ];
    }

    protected function storeLog(array $logEntry): void
    {
        DB::table('audit_logs')->insert([
            'event' => $logEntry['event'],
            'data' => json_encode($logEntry['data']),
            'user_id' => $logEntry['user_id'],
            'ip_address' => $logEntry['ip_address'],
            'created_at' => $logEntry['timestamp'],
            'context' => json_encode($logEntry['context'])
        ]);

        if ($this->requiresExternalLogging($logEntry['event'])) {
            $this->sendToExternalLogger($logEntry);
        }
    }

    protected function handleCriticalEvent(array $logEntry): void
    {
        Log::critical('Critical audit event', $logEntry);
        
        $this->security->handleCriticalEvent($logEntry);
        
        $this->notifyAdministrators($logEntry);
        
        Cache::tags(['audit'])->put(
            "critical_event:{$logEntry['event']}",
            $logEntry,
            now()->addDay()
        );
    }

    protected function isCriticalEvent(string $event): bool
    {
        return in_array($event, $this->criticalEvents) ||
            str_starts_with($event, 'critical.') ||
            $this->security->isCriticalEvent($event);
    }

    protected function requiresExternalLogging(string $event): bool
    {
        return config("audit.external_logging.{$event}", false) ||
            $this->isCriticalEvent($event);
    }

    protected function sendToExternalLogger(array $logEntry): void
    {
        try {
            $logger = app(config('audit.external_logger'));
            $logger->send($logEntry);
        } catch (\Exception $e) {
            Log::error('Failed to send to external logger', [
                'error' => $e->getMessage(),
                'log_entry' => $logEntry
            ]);
        }
    }

    protected function notifyAdministrators(array $logEntry): void
    {
        $notification = [
            'type' => 'critical_audit_event',
            'event' => $logEntry['event'],
            'timestamp' => $logEntry['timestamp'],
            'details' => $logEntry['data']
        ];

        $this->security->notifyAdministrators($notification);
    }

    public function getAuditTrail(array $filters = []): array
    {
        $this->security->validateAccess('audit.view');
        
        return DB::table('audit_logs')
            ->when(isset($filters['event']), function($query) use ($filters) {
                $query->where('event', $filters['event']);
            })
            ->when(isset($filters['user_id']), function($query) use ($filters) {
                $query->where('user_id', $filters['user_id']);
            })
            ->when(isset($filters['from']), function($query) use ($filters) {
                $query->where('created_at', '>=', $filters['from']);
            })
            ->when(isset($filters['to']), function($query) use ($filters) {
                $query->where('created_at', '<=', $filters['to']);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}
