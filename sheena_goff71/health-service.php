<?php

namespace App\Core\Health\Services;

use App\Core\Health\Models\HealthCheck;
use App\Core\Health\Repositories\HealthRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class HealthMonitorService
{
    public function __construct(
        private HealthRepository $repository,
        private SystemMetricsCollector $metricsCollector,
        private HealthNotifier $notifier
    ) {}

    public function check(): array
    {
        $checks = collect([
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'services' => $this->checkServices(),
            'memory' => $this->checkMemory(),
            'cpu' => $this->checkCPU()
        ]);

        $result = [
            'status' => $checks->contains('status', 'error') ? 'error' : 'healthy',
            'timestamp' => now(),
            'checks' => $checks->toArray()
        ];

        $this->repository->recordHealthCheck($result);

        if ($result['status'] === 'error') {
            $this->notifier->notifyHealthIssues($checks->where('status', 'error'));
        }

        return $result;
    }

    public function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return [
                'status' => 'healthy',
                'message' => 'Database connection successful',
                'metrics' => $this->metricsCollector->getDatabaseMetrics()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    public function checkCache(): array
    {
        try {
            Cache::set('health_check_test', true, 10);
            $value = Cache::get('health_check_test');
            Cache::forget('health_check_test');

            return [
                'status' => $value === true ? 'healthy' : 'error',
                'message' => $value === true ? 'Cache is working' : 'Cache test failed',
                'metrics' => $this->metricsCollector->getCacheMetrics()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Cache check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    public function checkQueue(): array
    {
        try {
            $metrics = $this->metricsCollector->getQueueMetrics();
            
            return [
                'status' => $metrics['failed_jobs'] > 0 ? 'warning' : 'healthy',
                'message' => $metrics['failed_jobs'] > 0 
                    ? 'Queue has failed jobs' 
                    : 'Queue is working',
                'metrics' => $metrics
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Queue check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    public function checkStorage(): array
    {
        try {
            $metrics = $this->metricsCollector->getStorageMetrics();
            $threshold = config('health.storage_threshold', 80);
            
            return [
                'status' => $metrics['disk_usage_percent'] > $threshold ? 'warning' : 'healthy',
                'message' => $metrics['disk_usage_percent'] > $threshold 
                    ? 'Storage usage is high' 
                    : 'Storage is healthy',
                'metrics' => $metrics
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Storage check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    protected function checkServices(): array
    {
        $services = config('health.services', []);
        $results = [];

        foreach ($services as $service) {
            $results[$service] = $this->checkService($service);
        }

        return [
            'status' => collect($results)->contains('status', 'error') ? 'error' : 'healthy',
            'message' => 'Service health check results',
            'checks' => $results
        ];
    }

    protected function checkMemory(): array
    {
        $metrics = $this->metricsCollector->getMemoryMetrics();
        $threshold = config('health.memory_threshold', 90);

        return [
            'status' => $metrics['memory_usage_percent'] > $threshold ? 'warning' : 'healthy',
            'message' => $metrics['memory_usage_percent'] > $threshold 
                ? 'Memory usage is high' 
                : 'Memory usage is normal',
            'metrics' => $metrics
        ];
    }

    protected function checkCPU(): array
    {
        $metrics = $this->metricsCollector->getCPUMetrics();
        $threshold = config('health.cpu_threshold', 80);

        return [
            'status' => $metrics['cpu_usage_percent'] > $threshold ? 'warning' : 'healthy',
            'message' => $metrics['cpu_usage_percent'] > $threshold 
                ? 'CPU usage is high' 
                : 'CPU usage is normal',
            'metrics' => $metrics
        ];
    }

    protected function checkService(string $service): array
    {
        try {
            $url = config("services.{$service}.health_url");
            $response = Http::timeout(5)->get($url);
            
            return [
                'status' => $response->successful() ? 'healthy' : 'error',
                'message' => $response->successful() 
                    ? 'Service is responding' 
                    : 'Service check failed',
                'response_time' => $response->handlerStats()['total_time'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => "Service {$service} check failed: " . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
}
