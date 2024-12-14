<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\AuditInterface;
use App\Core\Events\AuditEvent;
use App\Core\Exceptions\AuditException;

class AuditService implements AuditInterface
{
    private array $config;
    private array $batchEvents = [];
    private int $batchSize;
    private int $retentionDays;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->batchSize = $config['batch_size'] ?? 100;
        $this->retentionDays = $config['retention_days'] ?? 90;
    }

    public function log(string $type, array $data, array $context = []): void
    {
        try {
            $event = $this->createAuditEvent($type, $data, $context);
            
            if ($this->shouldBatch($type)) {
                $this->addToBatch($event);
            } else {
                $this->persistEvent($event);
            }
            
            $this->processRetention();
        } catch (\Exception $e) {
            throw new AuditException('Audit logging failed: ' . $e->getMessage());
        }
    }

    public function logSecurity(string $event, array $data = []): void
    {
        $this->log('security', $data, [
            'event' => $event,
            'priority' => 'high',
            'immediate' => true
        ]);
    }

    public function logAccess(array $data): void
    {
        $this->log('access', $data, [
            'ip' => $this->getClientIp(),
            'timestamp' => microtime(true)
        ]);
    }

    public function logFailure(\Throwable $e, array $context = []): void
    {
        $this->log('failure', [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], array_merge($context, ['priority' => 'critical']));
    }

    public function query(array $criteria): array
    {
        try {
            $cacheKey = $this->generateQueryCacheKey($criteria);
            
            return Cache::remember(
                $cacheKey,
                $this->config['query_cache_ttl'] ?? 300,
                fn() => $this->executeQuery($criteria)
            );
        } catch (\Exception $e) {
            throw new AuditException('Audit query failed: ' . $e->getMessage());
        }
    }

    protected function createAuditEvent(string $type, array $data, array $context): AuditEvent
    {
        return new AuditEvent([
            'type' => $type,
            'data' => $this->sanitizeData($data),
            'context' => $this->enrichContext($context),
            'timestamp' => microtime(true),
            'hash' => $this->generateEventHash($type, $data, $context)
        ]);
    }

    protected function shouldBatch(string $type): bool
    {
        return !in_array($type, $this->config['immediate_types'] ?? ['security', 'failure']);
    }

    protected function addToBatch(AuditEvent $event): void
    {
        $this->batchEvents[] = $event;
        
        if (count($this->batchEvents) >= $this->batchSize) {
            $this->flushBatch();
        }
    }

    protected function flushBatch(): void
    {
        if (empty($this->batchEvents)) {
            return;
        }

        DB::transaction(function() {
            foreach ($this->batchEvents as $event) {
                $this->persistEvent($event);
            }
        });

        $this->batchEvents = [];
    }

    protected function persistEvent(AuditEvent $event): void
    {
        DB::table($this->config['table'] ?? 'audit_logs')->insert([
            'type' => $event->type,
            'data' => json_encode($event->data),
            'context' => json_encode($event->context),
            'timestamp' => $event->timestamp,
            'hash' => $event->hash
        ]);
    }

    protected function processRetention(): void
    {
        if (random_int(1, 100) <= ($this->config['retention_check_probability'] ?? 1)) {
            $this->cleanupOldRecords();
        }
    }

    protected function cleanupOldRecords(): void
    {
        $cutoff = time() - ($this->retentionDays * 86400);
        
        DB::table($this->config['table'] ?? 'audit_logs')
            ->where('timestamp', '<', $cutoff)
            ->delete();
    }

    protected function sanitizeData(array $data): array
    {
        $sensitiveFields = $this->config['sensitive_fields'] ?? [
            'password', 'token', 'secret', 'key'
        ];

        return array_map(function($value) use ($sensitiveFields) {
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            
            return in_array(strtolower($value), $sensitiveFields) 
                ? '[REDACTED]' 
                : $value;
        }, $data);
    }

    protected function enrichContext(array $context): array
    {
        return array_merge($context, [
            'ip' => $context['ip'] ?? $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_id' => request()->id ?? null
        ]);
    }

    protected function generateEventHash(string $type, array $data, array $context): string
    {
        return hash('sha256', json_encode([
            'type' => $type,
            'data' => $data,
            'context' => $context,
            'timestamp' => microtime(true)
        ]));
    }

    protected function generateQueryCacheKey(array $criteria): string
    {
        return 'audit:query:' . md5(json_encode($criteria));
    }

    protected function executeQuery(array $criteria): array
    {
        return DB::table($this->config['table'] ?? 'audit_logs')
            ->where($criteria)
            ->orderBy('timestamp', 'desc')
            ->limit($this->config['query_limit'] ?? 1000)
            ->get()
            ->toArray();
    }

    protected function getClientIp(): ?string
    {
        return request()->ip();
    }

    public function __destruct()
    {
        $this->flushBatch();
    }
}
