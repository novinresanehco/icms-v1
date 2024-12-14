<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Redis;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\MonitoringInterface;

class MonitoringManager implements MonitoringInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private AuditLogger $audit;
    private array $thresholds;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        AlertManager $alerts,
        AuditLogger $audit,
        array $thresholds
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->audit = $audit;
        $this->thresholds = $thresholds;
    }

    public function track(string $metric, float $value, array $tags = []): void
    {
        $this->metrics->record($metric, $value, $tags);
        
        if ($this->isThresholdExceeded($metric, $value)) {
            $this->handleThresholdViolation($metric, $value, $tags);
        }
    }

    public function auditEvent(string $event, array $data, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new AuditOperation($event, $data, $this->audit),
            $context
        );
    }

    public function getMetrics(array $criteria): array
    {
        return $this->security->executeCriticalOperation(
            new GetMetricsOperation($criteria, $this->metrics),
            new SecurityContext('system')
        );
    }

    private function isThresholdExceeded(string $metric, float $value): bool
    {
        if (!isset($this->thresholds[$metric])) {
            return false;
        }

        $threshold = $this->thresholds[$metric];
        return $value >= $threshold['critical'] || $value <= $threshold['minimum'];
    }

    private function handleThresholdViolation(string $metric, float $value, array $tags): void
    {
        $this->alerts->trigger(
            new ThresholdAlert($metric, $value, $this->thresholds[$metric], $tags)
        );
    }
}

class MetricsCollector
{
    private Redis $redis;
    private string $prefix;
    private int $retention;

    public function record(string $metric, float $value, array $tags = []): void
    {
        $timestamp = time();
        $key = $this->getMetricKey($metric, $tags);
        
        $this->redis->zadd($key, $timestamp, json_encode([
            'value' => $value,
            'timestamp' => $timestamp,
            'tags' => $tags
        ]));
        
        $this->redis->expire($key, $this->retention);
        $this->updateAggregates($metric, $value, $tags);
    }

    public function query(array $criteria): array
    {
        $key = $this->getMetricKey($criteria['metric'], $criteria['tags'] ?? []);
        $start = $criteria['start'] ?? 0;
        $end = $criteria['end'] ?? time();
        
        return array_map(
            'json_decode',
            $this->redis->zrangebyscore($key, $start, $end)
        );
    }

    private function getMetricKey(string $metric, array $tags): string
    {
        $tagString = empty($tags) ? '' : ':' . implode(':', array_map(
            fn($k, $v) => "{$k}={$v}",
            array_keys($tags),
            array_values($tags)
        ));
        
        return "{$this->prefix}:{$metric}{$tagString}";
    }

    private function updateAggregates(string $metric, float $value, array $tags): void
    {
        $this->updateAverage($metric, $value, $tags);
        $this->updatePercentiles($metric, $value, $tags);
        $this->updateHistogram($metric, $value, $tags);
    }

    private function updateAverage(string $metric, float $value, array $tags): void
    {
        $key = $this->getMetricKey("{$metric}:avg", $tags);
        
        $this->redis->eval("
            local count = redis.call('hincrby', KEYS[1], 'count', 1)
            local sum = redis.call('hincrbyfloat', KEYS[1], 'sum', ARGV[1])
            redis.call('hset', KEYS[1], 'avg', sum/count)
            redis.call('expire', KEYS[1], ARGV[2])
        ", 1, $key, $value, $this->retention);
    }

    private function updatePercentiles(string $metric, float $value, array $tags): void
    {
        $key = $this->getMetricKey("{$metric}:percentiles", $tags);
        $this->redis->zadd($key, $value, $value);
        $this->redis->expire($key, $this->retention);
    }

    private function updateHistogram(string $metric, float $value, array $tags): void
    {
        $key = $this->getMetricKey("{$metric}:histogram", $tags);
        $bucket = floor($value / 10) * 10;
        
        $this->redis->hincrby($key, (string)$bucket, 1);
        $this->redis->expire($key, $this->retention);
    }
}

class AlertManager
{
    private array $handlers = [];
    private array $escalations;
    private AuditLogger $audit;

    public function trigger(Alert $alert): void
    {
        $this->audit->logAlert($alert);
        
        foreach ($this->getHandlers($alert->getSeverity()) as $handler) {
            $handler->handle($alert);
        }
        
        if ($this->requiresEscalation($alert)) {
            $this->escalate($alert);
        }
    }

    public function registerHandler(string $severity, AlertHandler $handler): void
    {
        $this->handlers[$severity][] = $handler;
    }

    private function getHandlers(string $severity): array
    {
        return $this->handlers[$severity] ?? [];
    }

    private function requiresEscalation(Alert $alert): bool
    {
        if (!isset($this->escalations[$alert->getMetric()])) {
            return false;
        }
        
        $config = $this->escalations[$alert->getMetric()];
        $count = $this->getRecentAlertCount($alert);
        
        return $count >= $config['threshold'];
    }

    private function getRecentAlertCount(Alert $alert): int
    {
        $key = "alerts:{$alert->getMetric()}:count";
        $window = $this->escalations[$alert->getMetric()]['window'];
        
        return Redis::zcount($key, time() - $window, time());
    }

    private function escalate(Alert $alert): void
    {
        $escalation = new EscalatedAlert($alert);
        
        foreach ($this->getHandlers('critical') as $handler) {
            $handler->handle($escalation);
        }
    }
}

class AuditLogger
{
    private string $table;
    private array $config;

    public function log(string $event, array $data, array $context = []): void
    {
        DB::table($this->table)->insert([
            'event' => $event,
            'data' => json_encode($data),
            'context' => json_encode($context),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now()
        ]);
    }

    public function logAlert(Alert $alert): void
    {
        $this->log('alert_triggered', [
            'metric' => $alert->getMetric(),
            'value' => $alert->getValue(),
            'threshold' => $alert->getThreshold(),
            'severity' => $alert->getSeverity()
        ]);
    }

    public function query(array $criteria): array
    {
        $query = DB::table($this->table);
        
        if (isset($criteria['event'])) {
            $query->where('event', $criteria['event']);
        }
        
        if (isset($criteria['start'])) {
            $query->where('created_at', '>=', $criteria['start']);
        }
        
        if (isset($criteria['end'])) {
            $query->where('created_at', '<=', $criteria['end']);
        }
        
        return $query->get()->toArray();
    }
}
