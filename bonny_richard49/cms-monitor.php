<?php

namespace App\Core\Monitor;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\MonitorException;

class MonitoringManager
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private HealthChecker $health;
    private AlertManager $alerts;
    private array $config;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        HealthChecker $health,
        AlertManager $alerts,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->health = $health;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function checkSystem(SecurityContext $context): SystemStatus
    {
        return $this->security->executeCriticalOperation(function() {
            // Collect current metrics
            $metrics = $this->metrics->collect();
            
            // Check health status
            $health = $this->health->check();
            
            // Process alerts
            $alerts = $this->alerts->process($metrics, $health);
            
            // Generate status report
            return new SystemStatus($metrics, $health, $alerts);
        }, $context);
    }

    public function getMetrics(string $type, SecurityContext $context): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->metrics->getByType($type),
            $context
        );
    }

    public function getAlerts(array $filters, SecurityContext $context): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->alerts->getFiltered($filters),
            $context
        );
    }
}

class MetricsCollector
{
    private array $collectors;
    private array $config;

    public function collect(): array
    {
        $metrics = [];
        
        foreach ($this->collectors as $type => $collector) {
            try {
                $metrics[$type] = $collector->collect();
            } catch (\Exception $e) {
                Log::error("Metrics collection failed for {$type}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $this->store($metrics);
        
        return $metrics;
    }

    public function getByType(string $type): array
    {
        return DB::table('system_metrics')
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->limit($this->config['metrics_history_limit'])
            ->get()
            ->toArray();
    }

    private function store(array $metrics): void
    {
        $timestamp = now();
        
        foreach ($metrics as $type => $data) {
            DB::table('system_metrics')->insert([
                'type' => $type,
                'data' => json_encode($data),
                'created_at' => $timestamp
            ]);
        }
    }
}

class HealthChecker
{
    private array $checks;
    private array $config;

    public function check(): array
    {
        $results = [];
        
        foreach ($this->checks as $component => $check) {
            try {
                $status = $check->execute();
                $results[$component] = new HealthStatus(
                    $component,
                    $status['status'],
                    $status['details'] ?? null
                );
            } catch (\Exception $e) {
                Log::error("Health check failed for {$component}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $results[$component] = new HealthStatus(
                    $component,
                    'error',
                    $e->getMessage()
                );
            }
        }
        
        return $results;
    }

    public function getStatus(string $component): ?HealthStatus
    {
        if (!isset($this->checks[$component])) {
            return null;
        }
        
        try {
            $status = $this->checks[$component]->execute();
            return new HealthStatus(
                $component,
                $status['status'],
                $status['details'] ?? null
            );
        } catch (\Exception $e) {
            return new HealthStatus($component, 'error', $e->getMessage());
        }
    }
}

class AlertManager
{
    private DB $db;
    private array $config;
    private array $handlers;

    public function process(array $metrics, array $health): array
    {
        $alerts = [];
        
        foreach ($this->handlers as $type => $handler) {
            try {
                $result = $handler->evaluate($metrics, $health);
                if ($result) {
                    $alerts[] = $this->createAlert($type, $result);
                }
            } catch (\Exception $e) {
                Log::error("Alert processing failed for {$type}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        return $alerts;
    }

    public function getFiltered(array $filters): array
    {
        $query = DB::table('system_alerts');
        
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        
        return $query
            ->orderBy('created_at', 'desc')
            ->limit($this->config['alerts_history_limit'])
            ->get()
            ->toArray();
    }

    private function createAlert(string $type, array $data): Alert
    {
        $id = DB::table('system_alerts')->insertGetId([
            'type' => $type,
            'severity' => $data['severity'],
            'message' => $data['message'],
            'details' => json_encode($data['details'] ?? []),
            'created_at' => now()
        ]);
        
        return new Alert(
            $id,
            $type,
            $data['severity'],
            $data['message'],
            $data['details'] ?? []
        );
    }
}

class SystemStatus
{
    private array $metrics;
    private array $health;
    private array $alerts;
    private string $timestamp;

    public function __construct(array $metrics, array $health, array $alerts)
    {
        $this->metrics = $metrics;
        $this->health = $health;
        $this->alerts = $alerts;
        $this->timestamp = now()->toDateTimeString();
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getHealth(): array
    {
        return $this->health;
    }

    public function getAlerts(): array
    {
        return $this->alerts;
    }

    public function getTimestamp(): string
    {
        return $this->timestamp;
    }

    public function isHealthy(): bool
    {
        foreach ($this->health as $status) {
            if ($status->getStatus() !== 'healthy') {
                return false;
            }
        }
        return true;
    }

    public function hasCriticalAlerts(): bool
    {
        foreach ($this->alerts as $alert) {
            if ($alert->getSeverity() === 'critical') {
                return true;
            }
        }
        return false;
    }
}

class HealthStatus
{
    private string $component;
    private string $status;
    private ?string $details;

    public function __construct(string $component, string $status, ?string $details = null)
    {
        $this->component = $component;
        $this->status = $status;
        $this->details = $details;
    }

    public function getComponent(): string
    {
        return $this->component;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }
}

class Alert
{
    private string $id;
    private string $type;
    private string $severity;
    private string $message;
    private array $details;

    public function __construct(
        string $id,
        string $type,
        string $severity,
        string $message,
        array $details = []
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->severity = $severity;
        $this->message = $message;
        $this->details = $details;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
