namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private AlertSystem $alerts;
    private LogManager $logs;
    private Config $config;

    public function track(Operation $operation): OperationResult
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);

        try {
            $this->beginMonitoring($operation);
            $result = $operation->execute();
            $this->validateResult($result);
            return $result;
        } catch (\Exception $e) {
            $this->handleFailure($operation, $e);
            throw $e;
        } finally {
            $this->recordMetrics($operation, [
                'duration' => microtime(true) - $startTime,
                'memory' => memory_get_usage(true) - $memoryStart
            ]);
        }
    }

    private function beginMonitoring(Operation $operation): void
    {
        DB::beginTransaction();

        $this->metrics->increment('operations.started');
        $this->metrics->gauge('operations.active', 1);

        $this->logs->info('operation.started', [
            'type' => $operation->getType(),
            'id' => $operation->getId(),
            'timestamp' => now()
        ]);
    }

    private function validateResult($result): void
    {
        if (!$result || !$result->isValid()) {
            throw new InvalidResultException();
        }

        $metrics = $result->getMetrics();
        
        foreach ($this->config->get('thresholds') as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $this->alerts->trigger(new ThresholdAlert($metric, $metrics[$metric]));
            }
        }
    }

    private function handleFailure(Operation $operation, \Exception $e): void
    {
        DB::rollBack();

        $this->metrics->increment('operations.failed');
        $this->metrics->gauge('operations.active', -1);

        $this->logs->error('operation.failed', [
            'type' => $operation->getType(),
            'id' => $operation->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->alerts->trigger(new OperationFailedAlert($operation, $e));
    }

    private function recordMetrics(Operation $operation, array $measurements): void
    {
        $this->metrics->histogram('operation.duration', $measurements['duration']);
        $this->metrics->histogram('operation.memory', $measurements['memory']);
        
        $this->metrics->gauge('operations.active', -1);
        
        if ($measurements['duration'] > $this->config->get('slow_threshold')) {
            $this->alerts->trigger(new SlowOperationAlert($operation, $measurements));
        }

        if ($measurements['memory'] > $this->config->get('memory_threshold')) {
            $this->alerts->trigger(new HighMemoryAlert($operation, $measurements));
        }

        $this->logs->info('operation.completed', [
            'type' => $operation->getType(),
            'id' => $operation->getId(),
            'duration' => $measurements['duration'],
            'memory' => $measurements['memory']
        ]);
    }

    public function checkHealth(): HealthStatus
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'services' => $this->checkExternalServices()
        ];

        $status = new HealthStatus($checks);

        if (!$status->isHealthy()) {
            $this->alerts->trigger(new SystemUnhealthyAlert($status));
        }

        return $status;
    }

    private function checkDatabase(): ComponentStatus
    {
        try {
            DB::connection()->getPdo();
            return new ComponentStatus('database', true);
        } catch (\Exception $e) {
            $this->alerts->trigger(new DatabaseConnectionAlert($e));
            return new ComponentStatus('database', false, $e->getMessage());
        }
    }

    private function checkCache(): ComponentStatus
    {
        try {
            Cache::store()->has('health_check');
            return new ComponentStatus('cache', true);
        } catch (\Exception $e) {
            $this->alerts->trigger(new CacheConnectionAlert($e));
            return new ComponentStatus('cache', false, $e->getMessage());
        }
    }

    private function checkStorage(): ComponentStatus
    {
        $disk = Storage::disk();
        $space = $disk->getAvailableSpace();
        
        if ($space < $this->config->get('min_disk_space')) {
            $this->alerts->trigger(new LowDiskSpaceAlert($space));
            return new ComponentStatus('storage', false, 'Low disk space');
        }

        return new ComponentStatus('storage', true);
    }

    private function checkQueue(): ComponentStatus
    {
        try {
            $failed = Queue::failed()->count();
            
            if ($failed > $this->config->get('max_failed_jobs')) {
                $this->alerts->trigger(new HighFailedJobsAlert($failed));
                return new ComponentStatus('queue', false, 'Too many failed jobs');
            }

            return new ComponentStatus('queue', true);
        } catch (\Exception $e) {
            $this->alerts->trigger(new QueueConnectionAlert($e));
            return new ComponentStatus('queue', false, $e->getMessage());
        }
    }

    private function checkExternalServices(): ComponentStatus
    {
        $services = $this->config->get('external_services');
        $results = [];

        foreach ($services as $service => $config) {
            try {
                $response = Http::timeout(5)->head($config['health_url']);
                $results[$service] = $response->successful();
                
                if (!$response->successful()) {
                    $this->alerts->trigger(new ServiceDownAlert($service));
                }
            } catch (\Exception $e) {
                $results[$service] = false;
                $this->alerts->trigger(new ServiceConnectionAlert($service, $e));
            }
        }

        return new ComponentStatus('services', !in_array(false, $results), $results);
    }
}
