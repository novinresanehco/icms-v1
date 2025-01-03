<?php

namespace App\Core\Monitoring;

use App\Core\Metrics\MetricsCollector;
use App\Core\Security\AuditLogger;

class HealthMonitor
{
    private MetricsCollector $metrics;
    private AuditLogger $audit;
    private array $thresholds;

    public function checkSystemHealth(): bool
    {
        try {
            $checks = [
                $this->checkServices(),
                $this->checkResources(),
                $this->checkDependencies(),
                $this->checkSecurity(),
                $this->checkPerformance()
            ];

            return !in_array(false, $checks, true);

        } catch (\Exception $e) {
            $this->handleHealthCheckFailure($e);
            return false;
        }
    }

    public function startDeploymentMode(): void
    {
        Cache::tags('system_status')->put('deployment_mode', true);
        $this->audit->logSystemStatus('deployment_started');
    }

    public function endDeploymentMode(): void
    {
        Cache::tags('system_status')->put('deployment_mode', false);
        $this->audit->logSystemStatus('deployment_ended');
    }

    public function checkDatabaseHealth(): HealthStatus
    {
        $status = new HealthStatus();

        try {
            // Check database connection
            DB::connection()->getPdo();
            $status->addCheck('connection', true);

            // Check database size
            $size = $this->getDatabaseSize();
            $status->addMetric('size', $size);
            $status->addCheck('size', $size < $this->thresholds['database_size']);

            // Check query performance
            $queryTime = $this->checkQueryPerformance();
            $status->addMetric('query_time', $queryTime);
            $status->addCheck('performance', $queryTime < $this->thresholds['query_time']);

            return $status;

        } catch (\Exception $e) {
            $status->setError($e);
            $this->handleDatabaseFailure($e);
            return $status;
        }
    }

    public function checkSecurityStatus(): HealthStatus
    {
        $status = new HealthStatus();

        try {
            // Check SSL certificate
            $certStatus = $this->checkSSLCertificate();
            $status->addCheck('ssl', $certStatus);

            // Check file permissions
            $permissionsStatus = $this->checkFilePermissions();
            $status->addCheck('permissions', $permissionsStatus);

            // Check security updates
            $updatesStatus = $this->checkSecurityUpdates();
            $status->addCheck('updates', $updatesStatus);

            return $status;

        } catch (\Exception $e) {
            $status->setError($e);
            $this->handleSecurityFailure($e);
            return $status;
        }
    }

    public function getSystemMetrics(): array
    {
        return [
            'cpu' => $this->metrics->getCPUUsage(),
            'memory' => $this->metrics->getMemoryUsage(),
            'disk' => $this->metrics->getDiskUsage(),
            'network' => $this->metrics->getNetworkStatus(),
            'services' => $this->getServiceStatus(),
            'security' => $this->getSecurityMetrics()
        ];
    }

    protected function checkServices(): bool
    {
        $services = config('monitoring.services');
        
        foreach ($services as $service => $config) {
            if (!$this->checkService($service, $config)) {
                return false;
            }
        }
        
        return true;
    }

    protected function checkResources(): bool
    {
        $cpu = $this->metrics->getCPUUsage();
        $memory = $this->metrics->getMemoryUsage();
        $disk = $this->metrics->getDiskUsage();

        return $cpu < $this->thresholds['cpu_usage'] &&
               $memory < $this->thresholds['memory_usage'] &&
               $disk < $this->thresholds['disk_usage'];
    }

    protected function checkDependencies(): bool
    {
        foreach (config('monitoring.dependencies') as $dependency) {
            if (!$this->checkDependency($dependency)) {
                return false;
            }
        }
        return true;
    }

    protected function checkPerformance(): bool
    {
        $metrics = $this->metrics->getPerformanceMetrics();

        return $metrics['response_time'] < $this->thresholds['response_time'] &&
               $metrics['error_rate'] < $this->thresholds['error_rate'] &&
               $metrics['queue_size'] < $this->thresholds['queue_size'];
    }

    protected function checkService(string $service, array $config): bool
    {
        try {
            $response = Http::timeout(5)->get($config['url']);
            
            return $response->successful() &&
                   $this->validateServiceResponse($response, $config);
                   
        } catch (\Exception $e) {
            $this->handleServiceFailure($service, $e);
            return false;
        }
    }

    protected function validateServiceResponse($response, array $config): bool
    {
        return (!isset($config['expected_status']) || 
                $response->status() === $config['expected_status']) &&
               (!isset($config['expected_content']) || 
                str_contains($response->body(), $config['expected_content']));
    }

    protected function checkQueryPerformance(): float
    {
        $start = microtime(true);
        DB::select('SELECT 1');
        return microtime(true) - $start;
    }

    protected function getDatabaseSize(): int
    {
        $result = DB::select("
            SELECT pg_database_size(current_database()) as size
        ");
        return $result[0]->size;
    }

    protected function handleHealthCheckFailure(\Exception $e): void
    {
        $this->audit->logSystemFailure('health_check', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyAdministrators('Health Check Failed', $e);
    }

    protected function handleDatabaseFailure(\Exception $e): void
    {
        $this->audit->logSystemFailure('database', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyAdministrators('Database Health Check Failed', $e);
    }

    protected function handleServiceFailure(string $service, \Exception $e): void
    {
        $this->audit->logSystemFailure("service.{$service}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyAdministrators("Service {$service} Health Check Failed", $e);
    }

    protected function notifyAdministrators(string $subject, \Exception $e): void
    {
        Notification::route('mail', config('monitoring.admin_email'))
            ->notify(new SystemHealthNotification($subject, $e));
    }
}
