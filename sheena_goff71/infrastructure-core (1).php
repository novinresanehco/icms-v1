<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, DB, Log};

class InfrastructureManager
{
    private array $metrics = [];
    private int $errorThreshold = 50;
    private array $criticalServices = ['db', 'cache', 'storage'];

    public function checkSystemHealth(): array
    {
        $status = [];
        
        foreach ($this->criticalServices as $service) {
            try {
                $status[$service] = $this->checkService($service);
            } catch (\Throwable $e) {
                $status[$service] = false;
                $this->handleServiceFailure($service, $e);
            }
        }

        $this->recordMetrics($status);
        return $status;
    }

    private function checkService(string $service): bool
    {
        return match($service) {
            'db' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            default => throw new \InvalidArgumentException('Invalid service')
        };
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            Cache::set('health_check', true, 1);
            return Cache::get('health_check', false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkStorage(): bool
    {
        return is_writable(storage_path());
    }

    public function monitorPerformance(): array
    {
        $metrics = [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'errors' => $this->getErrorCount(),
            'response_time' => $this->getAverageResponseTime()
        ];

        $this->metrics[] = $metrics;
        $this->checkThresholds($metrics);

        return $metrics;
    }

    private function handleServiceFailure(string $service, \Throwable $e): void
    {
        Log::critical("Service failure: {$service}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($service)) {
            $this->triggerEmergencyProtocol($service);
        }
    }

    private function isCriticalFailure(string $service): bool
    {
        return in_array($service, ['db', 'cache']);
    }

    private function triggerEmergencyProtocol(string $service): void
    {
        // Emergency system recovery
        try {
            match($service) {
                'db' => $this->reconnectDatabase(),
                'cache' => $this->resetCache(),
                default => null
            };
        } catch (\Throwable $e) {
            Log::emergency("Emergency protocol failed: {$service}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function reconnectDatabase(): void
    {
        DB::reconnect();
    }

    private function resetCache(): void
    {
        Cache::flush();
    }

    private function getErrorCount(): int
    {
        return count(Log::getMonolog()->getHandlers()[0]->getRecords());
    }

    private function getAverageResponseTime(): float
    {
        return array_sum(array_column($this->metrics, 'response_time')) / max(1, count($this->metrics));
    }

    private function checkThresholds(array $metrics): void
    {
        if ($metrics['errors'] > $this->errorThreshold) {
            Log::alert('Error threshold exceeded', $metrics);
        }

        if ($metrics['memory'] > 128 * 1024 * 1024) {
            Log::warning('High memory usage', $metrics);
        }

        if ($metrics['cpu'] > 0.8) {
            Log::warning('High CPU usage', $metrics);
        }
    }

    private function recordMetrics(array $status): void
    {
        foreach ($status as $service => $health) {
            $key = "metrics.health.{$service}";
            $history = Cache::get($key, []);
            $history[] = ['time' => time(), 'status' => $health];
            
            // Keep last 100 records
            if (count($history) > 100) {
                array_shift($history);
            }
            
            Cache::put($key, $history, 3600);
        }
    }
}
