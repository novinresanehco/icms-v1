namespace App\Core\Audit;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;

class AuditManager implements AuditInterface 
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private EventDispatcher $events;
    
    private const CRITICAL_EVENTS = [
        'security.breach',
        'auth.failed',
        'data.corruption',
        'system.error'
    ];

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        EventDispatcher $events
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->events = $events;
    }

    public function track(string $event, array $context = []): void 
    {
        $startTime = microtime(true);
        
        try {
            DB::transaction(function() use ($event, $context) {
                $entry = $this->createAuditEntry($event, $context);
                
                if ($this->isCriticalEvent($event)) {
                    $this->handleCriticalEvent($entry);
                }
                
                $this->storeMetrics($event, $entry);
                $this->dispatchAuditEvent($entry);
            });
            
        } catch (\Throwable $e) {
            Log::emergency('Audit system failure', [
                'event' => $event,
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new AuditException('Critical audit failure', 0, $e);
        } finally {
            $this->metrics->recordAuditTime(microtime(true) - $startTime);
        }
    }

    public function monitor(string $resource, callable $operation): mixed 
    {
        $context = [
            'resource' => $resource,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];

        try {
            $result = $operation();
            
            $context['status'] = 'success';
            $context['execution_time'] = microtime(true) - $context['start_time'];
            $context['memory_used'] = memory_get_usage(true) - $context['memory_start'];
            
            $this->track('resource.accessed', $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $context['status'] = 'error';
            $context['error'] = $e->getMessage();
            
            $this->track('resource.failed', $context);
            
            throw $e;
        }
    }

    public function analyze(string $pattern, \DateTime $start, \DateTime $end): array 
    {
        return $this->security->executeSecureOperation(function() use ($pattern, $start, $end) {
            $cacheKey = "audit:analysis:{$pattern}:{$start->getTimestamp()}:{$end->getTimestamp()}";
            
            return Cache::remember($cacheKey, 3600, function() use ($pattern, $start, $end) {
                $entries = DB::table('audit_log')
                    ->where('event', 'LIKE', $pattern)
                    ->whereBetween('created_at', [$start, $end])
                    ->get();
                
                return $this->analyzeEntries($entries);
            });
        }, ['action' => 'audit.analyze']);
    }

    private function createAuditEntry(string $event, array $context): AuditEntry 
    {
        return new AuditEntry([
            'event' => $event,
            'user_id' => $context['user_id'] ?? auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'context' => json_encode($context),
            'created_at' => now()
        ]);
    }

    private function handleCriticalEvent(AuditEntry $entry): void 
    {
        // Immediate notification
        $this->notifySecurityTeam($entry);
        
        // Log to separate critical events log
        Log::critical('Critical security event', [
            'event' => $entry->event,
            'context' => $entry->context,
            'user_id' => $entry->user_id,
            'ip' => $entry->ip_address
        ]);
        
        // Update security metrics
        $this->metrics->incrementCriticalEvents($entry->event);
        
        // Store for analysis
        $this->storeCriticalEvent($entry);
    }

    private function analyzeEntries(Collection $entries): array 
    {
        $analysis = [
            'count' => $entries->count(),
            'events_by_type' => [],
            'events_by_user' => [],
            'events_by_hour' => array_fill(0, 24, 0),
            'critical_events' => 0,
            'average_response_time' => 0
        ];

        $totalTime = 0;
        $timeCount = 0;

        foreach ($entries as $entry) {
            // Analyze by type
            $analysis['events_by_type'][$entry->event] ??= 0;
            $analysis['events_by_type'][$entry->event]++;
            
            // Analyze by user
            $analysis['events_by_user'][$entry->user_id] ??= 0;
            $analysis['events_by_user'][$entry->user_id]++;
            
            // Analyze by hour
            $hour = (new \DateTime($entry->created_at))->format('G');
            $analysis['events_by_hour'][$hour]++;
            
            // Count critical events
            if ($this->isCriticalEvent($entry->event)) {
                $analysis['critical_events']++;
            }
            
            // Calculate response times
            $context = json_decode($entry->context, true);
            if (isset($context['execution_time'])) {
                $totalTime += $context['execution_time'];
                $timeCount++;
            }
        }

        if ($timeCount > 0) {
            $analysis['average_response_time'] = $totalTime / $timeCount;
        }

        return $analysis;
    }

    private function isCriticalEvent(string $event): bool 
    {
        return in_array($event, self::CRITICAL_EVENTS);
    }

    private function notifySecurityTeam(AuditEntry $entry): void 
    {
        // Implementation depends on notification system
        // But must be handled without throwing exceptions
        try {
            $this->events->dispatch(new SecurityNotification($entry));
        } catch (\Throwable $e) {
            Log::error('Failed to notify security team', [
                'error' => $e->getMessage(),
                'event' => $entry->event
            ]);
        }
    }

    private function storeCriticalEvent(AuditEntry $entry): void 
    {
        DB::table('critical_events')->insert([
            'audit_entry_id' => $entry->id,
            'severity' => $this->calculateSeverity($entry),
            'status' => 'new',
            'created_at' => now()
        ]);
    }

    private function calculateSeverity(AuditEntry $entry): string 
    {
        $severityMap = [
            'security.breach' => 'critical',
            'auth.failed' => 'high',
            'data.corruption' => 'critical',
            'system.error' => 'high'
        ];

        return $severityMap[$entry->event] ?? 'medium';
    }

    private function storeMetrics(string $event, AuditEntry $entry): void 
    {
        $context = json_decode($entry->context, true);
        
        if (isset($context['execution_time'])) {
            $this->metrics->recordExecutionTime($event, $context['execution_time']);
        }
        
        if (isset($context['memory_used'])) {
            $this->metrics->recordMemoryUsage($event, $context['memory_used']);
        }
        
        $this->metrics->incrementEventCount($event);
    }

    private function dispatchAuditEvent(AuditEntry $entry): void 
    {
        $this->events->dispatch(new AuditEvent($entry));
    }
}
