<?php

namespace App\Core\Monitoring\Models;

class SystemMetric extends Model
{
    protected $fillable = [
        'name',
        'value',
        'type',
        'tags',
        'timestamp'
    ];

    protected $casts = [
        'value' => 'float',
        'tags' => 'array',
        'timestamp' => 'datetime'
    ];
}

class HealthCheck extends Model
{
    protected $fillable = [
        'name',
        'status',
        'message',
        'last_check_at',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_check_at' => 'datetime'
    ];
}

namespace App\Core\Monitoring\Services;

class MonitoringManager
{
    private MetricsCollector $collector;
    private HealthChecker $healthChecker;
    private AlertManager $alertManager;

    public function collect(): void
    {
        $metrics = $this->collector->collect();
        $this->checkThresholds($metrics);
    }

    public function checkHealth(): array
    {
        return $this->healthChecker->check();
    }

    private function checkThresholds(array $metrics): void
    {
        foreach ($metrics as $metric) {
            $this->alertManager->checkThresholds($metric);
        }
    }
}

class MetricsCollector
{
    private array $collectors = [];

    public function addCollector(string $name, CollectorInterface $collector): void
    {
        $this->collectors[$name] = $collector;
    }

    public function collect(): array
    {
        $metrics = [];
        foreach ($this->collectors as $collector) {
            $metrics = array_merge($metrics, $collector->collect());
        }
        return $metrics;
    }
}

class HealthChecker
{
    private array $checks = [];

    public function addCheck(string $name, HealthCheckInterface $check): void
    {
        $this->checks[$name] = $check;
    }

    public function check(): array
    {
        $results = [];
        foreach ($this->checks as $name => $check) {
            $results[$name] = $check->check();
        }
        return $results;
    }
}

class AlertManager
{
    private array $rules = [];
    private NotificationManager $notifications;

    public function addRule(string $metric, AlertRule $rule): void
    {
        $this->rules[$metric][] = $rule;
    }

    public function checkThresholds(SystemMetric $metric): void
    {
        if (!isset($this->rules[$metric->name])) {
            return;
        }

        foreach ($this->rules[$metric->name] as $rule) {
            if ($rule->isTriggered($metric)) {
                $this->notifications->send($rule->createAlert($metric));
            }
        }
    }
}

namespace App\Core\Monitoring\Collectors;

class SystemResourceCollector implements CollectorInterface
{
    public function collect(): array
    {
        return [
            new SystemMetric([
                'name' => 'cpu_usage',
                'value' => $this->getCpuUsage(),
                'type' => 'gauge',
                'timestamp' => now()
            ]),
            new SystemMetric([
                'name' => 'memory_usage',
                'value' => $this->getMemoryUsage(),
                'type' => 'gauge',
                'timestamp' => now()
            ]),
            new SystemMetric([
                'name' => 'disk_usage',
                'value' => $this->getDiskUsage(),
                'type' => 'gauge',
                'timestamp' => now()
            ])
        ];
    }

    private function getCpuUsage(): float
    {
        return sys_getloadavg()[0];
    }

    private function getMemoryUsage(): float
    {
        $memInfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s*(\d+)/', $memInfo, $matches);
        $total = $matches[1];
        preg_match('/MemFree:\s*(\d+)/', $memInfo, $matches);
        $free = $matches[1];
        return ($total - $free) / $total * 100;
    }

    private function getDiskUsage(): float
    {
        $disk = disk_free_space('/') / disk_total_space('/');
        return (1 - $disk) * 100;
    }
}

namespace App\Core\Monitoring\HealthChecks;

class DatabaseHealthCheck implements HealthCheckInterface
{
    public function check(): array
    {
        try {
            DB::connection()->getPdo();
            return [
                'status' => 'healthy',
                'message' => 'Database connection successful'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
    }
}

class CacheHealthCheck implements HealthCheckInterface
{
    public function check(): array
    {
        try {
            Cache::store()->get('health-check-test');
            return [
                'status' => 'healthy',
                'message' => 'Cache is working properly'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Cache check failed: ' . $e->getMessage()
            ];
        }
    }
}

namespace App\Core\Monitoring\Http\Controllers;

class MonitoringController extends Controller
{
    private MonitoringManager $monitoring;

    public function metrics(): JsonResponse
    {
        $metrics = SystemMetric::latest()
            ->limit(100)
            ->get();
            
        return response()->json($metrics);
    }

    public function health(): JsonResponse
    {
        $health = $this->monitoring->checkHealth();
        $status = collect($health)->every(fn($check) => $check['status'] === 'healthy')
            ? 'healthy'
            : 'unhealthy';
            
        return response()->json([
            'status' => $status,
            'checks' => $health
        ]);
    }
}

namespace App\Core\Monitoring\Console;

class CollectMetricsCommand extends Command
{
    protected $signature = 'monitoring:collect';

    public function handle(MonitoringManager $monitoring): void
    {
        $monitoring->collect();
        $this->info('Metrics collected successfully.');
    }
}
