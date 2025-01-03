<?php

namespace App\Core\Monitoring;

class AuditLogger implements AuditLoggerInterface
{
    private LogManager $logger;
    private MetricsCollector $metrics;

    public function logSecurityEvent(string $event, array $context): void
    {
        $this->logger->channel('security')->info($event, [
            'context' => $context,
            'timestamp' => now(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        $this->metrics->increment("security.{$event}");
    }

    public function logFailedLogin(string $email): void
    {
        $this->logSecurityEvent('auth.login.failed', [
            'email' => $email,
            'attempt' => $this->getLoginAttempts($email)
        ]);
    }

    public function logUnauthorizedAccess(User $user, string $permission): void
    {
        $this->logSecurityEvent('auth.access.denied', [
            'user' => $user->id,
            'permission' => $permission
        ]);
    }

    private function getLoginAttempts(string $email): int
    {
        return Cache::increment("login_attempts:{$email}");
    }
}

class MetricsCollector implements MetricsCollectorInterface
{
    private array $metrics = [];

    public function increment(string $metric, int $value = 1): void
    {
        $this->metrics[$metric] = ($this->metrics[$metric] ?? 0) + $value;
    }

    public function measure(string $metric, float $value): void
    {
        $this->metrics[$metric] = $value;
    }

    public function flush(): array
    {
        try {
            return $this->metrics;
        } finally {
            $this->metrics = [];
        }
    }
}

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function recordMetrics(): void
    {
        $this->metrics->measure('system.memory', memory_get_usage(true));
        $this->metrics->measure('system.cpu', sys_getloadavg()[0]);
        
        foreach (DB::getQueryLog() as $query) {
            $this->metrics->increment('db.queries');
            $this->metrics->measure('db.time', $query['time']);
        }
    }

    public function checkHealth(): HealthStatus
    {
        $status = new HealthStatus();

        $status->memory = memory_get_usage(true) < config('monitoring.memory_limit');
        $status->cpu = sys_getloadavg()[0] < config('monitoring.cpu_limit');
        $status->disk = disk_free_space('/') > config('monitoring.disk_minimum');

        if (!$status->isHealthy()) {
            $this->audit->logSystemHealth($status);
        }

        return $status;
    }

    public function detectAnomalies(): void
    {
        $metrics = $this->metrics->flush();

        foreach ($metrics as $metric => $value) {
            if ($this->isAnomaly($metric, $value)) {
                $this->audit->logAnomaly($metric, $value);
            }
        }
    }

    private function isAnomaly(string $metric, $value): bool
    {
        $threshold = config("monitoring.thresholds.{$metric}");
        return $threshold && $value > $threshold;
    }
}
