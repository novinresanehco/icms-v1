<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\AuditEvent;
use App\Core\Exceptions\AuditException;
use Illuminate\Support\Facades\{DB, Log};

class AuditManager implements AuditInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $config;
    private array $criticalEvents = [
        'auth.failure',
        'security.breach',
        'data.corruption',
        'system.error',
        'permission.violation'
    ];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = array_merge([
            'retention_period' => 90,
            'batch_size' => 100,
            'critical_alert' => true,
            'performance_tracking' => true
        ], $config);
    }

    public function log(string $event, array $data, array $context = []): void
    {
        $startTime = microtime(true);

        try {
            $this->validateEvent($event, $data);
            
            $entry = $this->prepareAuditEntry($event, $data, $context);
            
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($entry);
            } else {
                $this->queueAuditEntry($entry);
            }

            if ($this->config['performance_tracking']) {
                $this->trackPerformance($event, microtime(true) - $startTime);
            }
            
        } catch (\Exception $e) {
            $this->handleAuditFailure($event, $data, $e);
        }
    }

    public function query(array $criteria = [], array $options = []): array
    {
        return $this->security->executeCriticalOperation(
            function() use ($criteria, $options) {
                $query = DB::table('audit_logs')
                    ->when(isset($criteria['event']), function($q) use ($criteria) {
                        $q->where('event', $criteria['event']);
                    })
                    ->when(isset($criteria['start_date']), function($q) use ($criteria) {
                        $q->where('created_at', '>=', $criteria['start_date']);
                    })
                    ->when(isset($criteria['end_date']), function($q) use ($criteria) {
                        $q->where('created_at', '<=', $criteria['end_date']);
                    })
                    ->when(isset($criteria['user_id']), function($q) use ($criteria) {
                        $q->where('user_id', $criteria['user_id']);
                    });

                $total = $query->count();
                
                $results = $query
                    ->orderBy($options['sort_by'] ?? 'created_at', $options['sort_dir'] ?? 'desc')
                    ->skip($options['offset'] ?? 0)
                    ->take($options['limit'] ?? 100)
                    ->get();

                return [
                    'total' => $total,
                    'results' => $results
                ];
            },
            ['operation' => 'audit_query']
        );
    }

    public function getStatistics(array $criteria = []): array
    {
        return $this->cache->remember(
            $this->getStatsCacheKey($criteria),
            3600,
            function() use ($criteria) {
                return [
                    'total_events' => $this->getTotalEvents($criteria),
                    'events_by_type' => $this->getEventsByType($criteria),
                    'critical_events' => $this->getCriticalEvents($criteria),
                    'user_activity' => $this->getUserActivity($criteria)
                ];
            }
        );
    }

    public function cleanup(int $days = null): int
    {
        return $this->security->executeCriticalOperation(
            function() use ($days) {
                $days = $days ?? $this->config['retention_period'];
                $cutoff = now()->subDays($days);

                return DB::table('audit_logs')
                    ->where('created_at', '<', $cutoff)
                    ->delete();
            },
            ['operation' => 'audit_cleanup']
        );
    }

    protected function validateEvent(string $event, array $data): void
    {
        if (empty($event)) {
            throw new AuditException('Event type is required');
        }

        if (strlen(json_encode($data)) > 65535) {
            throw new AuditException('Audit data exceeds maximum size');
        }
    }

    protected function prepareAuditEntry(string $event, array $data, array $context): array
    {
        return [
            'event' => $event,
            'data' => $this->sanitizeData($data),
            'user_id' => $context['user_id'] ?? null,
            'ip_address' => $context['ip_address'] ?? request()->ip(),
            'user_agent' => $context['user_agent'] ?? request()->userAgent(),
            'created_at' => now(),
            'hash' => $this->generateEntryHash($event, $data)
        ];
    }

    protected function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if ($this->isSensitiveField($key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = is_array($value) ? $this->sanitizeData($value) : $value;
            }
        }
        return $sanitized;
    }

    protected function isSensitiveField(string $field): bool
    {
        return in_array(strtolower($field), [
            'password', 'token', 'secret', 'credit_card',
            'api_key', 'private_key', 'authorization'
        ]);
    }

    protected function generateEntryHash(string $event, array $data): string
    {
        return hash_hmac('sha256', $event . json_encode($data), config('app.key'));
    }

    protected function handleCriticalEvent(array $entry): void
    {
        DB::transaction(function() use ($entry) {
            DB::table('audit_logs')->insert($entry);
            
            if ($this->config['critical_alert']) {
                event(new AuditEvent('critical_audit', $entry));
            }
        });

        Log::critical('Critical audit event', $entry);
    }

    protected function queueAuditEntry(array $entry): void
    {
        $batch = $this->cache->remember('audit_batch', 60, function() {
            return [];
        });

        $batch[] = $entry;

        if (count($batch) >= $this->config['batch_size']) {
            $this->processBatch($batch);
            $this->cache->forget('audit_batch');
        } else {
            $this->cache->put('audit_batch', $batch, 60);
        }
    }

    protected function processBatch(array $batch): void
    {
        DB::transaction(function() use ($batch) {
            foreach (array_chunk($batch, 1000) as $chunk) {
                DB::table('audit_logs')->insert($chunk);
            }
        });
    }

    protected function isCriticalEvent(string $event): bool
    {
        return in_array($event, $this->criticalEvents);
    }

    protected function handleAuditFailure(string $event, array $data, \Exception $e): void
    {
        Log::error('Audit logging failed', [
            'event' => $event,
            'data' => $data,
            'error' => $e->getMessage()
        ]);

        if ($this->isCriticalEvent($event)) {
            throw new AuditException('Critical audit logging failed: ' . $e->getMessage());
        }
    }

    protected function trackPerformance(string $event, float $duration): void
    {
        $this->cache->remember("audit_perf.{$event}", 3600, function() use ($event, $duration) {
            return [
                'count' => 1,
                'total_duration' => $duration,
                'max_duration' => $duration,
                'min_duration' => $duration
            ];
        });
    }

    protected function getStatsCacheKey(array $criteria): string
    {
        return 'audit_stats.' . md5(serialize($criteria));
    }

    protected function getTotalEvents(array $criteria): int
    {
        return DB::table('audit_logs')
            ->where($this->buildCriteria($criteria))
            ->count();
    }

    protected function buildCriteria(array $criteria): array
    {
        return array_filter($criteria, function($value) {
            return $value !== null;
        });
    }
}
