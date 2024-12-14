<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Exceptions\{AuditException, ValidationException};

class AuditManager implements AuditInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $config;
    
    private const BATCH_SIZE = 100;
    private const RETENTION_DAYS = 90;
    private const CRITICAL_EVENTS = [
        'auth.failed',
        'security.breach',
        'data.corruption',
        'system.error'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function log(string $event, array $data, string $level = 'info'): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeLog($event, $data, $level),
            ['action' => 'audit.log', 'event' => $event]
        );
    }

    protected function executeLog(string $event, array $data, string $level): void
    {
        $validated = $this->validator->validate([
            'event' => $event,
            'data' => $data,
            'level' => $level
        ], [
            'event' => 'required|string|max:100',
            'data' => 'array',
            'level' => 'required|in:debug,info,warning,error,critical'
        ]);

        $logEntry = [
            'event' => $validated['event'],
            'data' => json_encode($validated['data']),
            'level' => $validated['level'],
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ];

        try {
            DB::table('audit_logs')->insert($logEntry);

            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($event, $data);
            }

            if ($level === 'critical') {
                Log::critical($event, $data);
            }

        } catch (\Exception $e) {
            Log::error('Failed to write audit log', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            throw new AuditException('Failed to write audit log: ' . $e->getMessage());
        }
    }

    public function query(array $filters): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeQuery($filters),
            ['action' => 'audit.query', 'filters' => $filters]
        );
    }

    protected function executeQuery(array $filters): array
    {
        $validated = $this->validator->validate($filters, [
            'start_date' => 'date',
            'end_date' => 'date|after:start_date',
            'level' => 'string|in:debug,info,warning,error,critical',
            'event' => 'string|max:100',
            'user_id' => 'integer',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:10|max:100'
        ]);

        $query = DB::table('audit_logs')
            ->select('id', 'event', 'level', 'user_id', 'ip_address', 'created_at')
            ->orderBy('created_at', 'desc');

        if (isset($validated['start_date'])) {
            $query->where('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('created_at', '<=', $validated['end_date']);
        }

        if (isset($validated['level'])) {
            $query->where('level', $validated['level']);
        }

        if (isset($validated['event'])) {
            $query->where('event', 'LIKE', "%{$validated['event']}%");
        }

        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 50;

        $total = $query->count();
        $results = $query->forPage($page, $perPage)->get();

        return [
            'data' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => ceil($total / $perPage)
        ];
    }

    public function getDetails(int $logId): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeGetDetails($logId),
            ['action' => 'audit.get_details', 'log_id' => $logId]
        );
    }

    protected function executeGetDetails(int $logId): array
    {
        $log = DB::table('audit_logs')->find($logId);

        if (!$log) {
            throw new AuditException('Audit log entry not found');
        }

        return (array)$log;
    }

    public function cleanup(): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeCleanup(),
            ['action' => 'audit.cleanup']
        );
    }

    protected function executeCleanup(): void
    {
        try {
            $cutoffDate = now()->subDays(self::RETENTION_DAYS);
            
            DB::table('audit_logs')
                ->where('created_at', '<', $cutoffDate)
                ->whereNotIn('level', ['error', 'critical'])
                ->chunkById(self::BATCH_SIZE, function($logs) {
                    foreach ($logs as $log) {
                        DB::table('audit_logs')
                            ->where('id', $log->id)
                            ->delete();
                    }
                });

        } catch (\Exception $e) {
            Log::error('Failed to cleanup audit logs', [
                'error' => $e->getMessage()
            ]);
            throw new AuditException('Failed to cleanup audit logs: ' . $e->getMessage());
        }
    }

    protected function isCriticalEvent(string $event): bool
    {
        return in_array($event, self::CRITICAL_EVENTS);
    }

    protected function handleCriticalEvent(string $event, array $data): void
    {
        // Alert security team
        $this->notifySecurityTeam($event, $data);

        // Store in separate critical events log
        DB::table('critical_events')->insert([
            'event' => $event,
            'data' => json_encode($data),
            'handled' => false,
            'created_at' => now()
        ]);

        // Cache for real-time monitoring
        Cache::put(
            "critical_event:{$event}:" . time(),
            $data,
            now()->addHours(24)
        );
    }

    protected function notifySecurityTeam(string $event, array $data): void
    {
        try {
            // Implementation depends on notification system
            // but must not throw exceptions
        } catch (\Exception $e) {
            Log::error('Failed to notify security team', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }
}
