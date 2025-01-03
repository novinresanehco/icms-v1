<?php
namespace App\Core\Health;

class SystemHealthMonitor implements HealthMonitorInterface
{
    private SecurityManager $security;
    private HealthCheckRunner $runner;
    private HealthRepository $repository;
    private AlertSystem $alerts;

    public function check(SecurityContext $context): HealthReport
    {
        return $this->security->executeCriticalOperation(
            new SystemHealthCheckOperation(
                $this->runner,
                $this->repository,
                $this->alerts
            ),
            $context
        );
    }
}

class SystemHealthCheckOperation extends CriticalOperation
{
    private HealthCheckRunner $runner;
    private HealthRepository $repository;
    private AlertSystem $alerts;

    public function execute(): HealthReport
    {
        // Run health checks
        $checks = $this->runner->runChecks();
        
        // Store results
        $report = $this->repository->storeResults($checks);
        
        // Check for critical issues
        $this->checkForCriticalIssues($checks);
        
        // Return report
        return $report;
    }

    private function checkForCriticalIssues(array $checks): void
    {
        $critical = array_filter($checks, fn($check) => $check->status === 'critical');
        
        if (!empty($critical)) {
            $this->alerts->sendCriticalHealthAlert($critical);
        }
    }

    public function getRequiredPermissions(): array
    {
        return ['system.health.check'];
    }
}

class HealthCheckRunner
{
    private array $checks = [];

    public function __construct()
    {
        $this->registerChecks();
    }

    public function runChecks(): array
    {
        $results = [];
        
        foreach ($this->checks as $check) {
            try {
                $results[] = $check->run();
            } catch (\Exception $e) {
                $results[] = new HealthCheckResult($check, 'critical', $e->getMessage());
            }
        }
        
        return $results;
    }

    private function registerChecks(): void
    {
        $this->checks = [
            new DatabaseHealthCheck(),
            new CacheHealthCheck(),
            new StorageHealthCheck(),
            new QueueHealthCheck(),
            new MemoryHealthCheck(),
            new CpuHealthCheck()
        ];
    }
}

abstract class HealthCheck
{
    abstract public function run(): HealthCheckResult;
    abstract public function getName(): string;
}

class DatabaseHealthCheck extends HealthCheck
{
    public function run(): HealthCheckResult
    {
        try {
            DB::connection()->getPdo();
            return new HealthCheckResult($this, 'ok');
        } catch (\Exception $e) {
            return new HealthCheckResult($this, 'critical', $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'database';
    }
}

class CacheHealthCheck extends HealthCheck
{
    public function run(): HealthCheckResult
    {
        try {
            Cache::store()->connection()->ping();
            return new HealthCheckResult($this, 'ok');
        } catch (\Exception $e) {
            return new HealthCheckResult($this, 'critical', $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'cache';
    }
}

class StorageHealthCheck extends HealthCheck
{
    public function run(): HealthCheckResult
    {
        $disk = Storage::disk();
        
        if (!$disk->exists('health.txt')) {
            $disk->put('health.txt', 'check');
        }
        
        if ($disk->get('health.txt') !== 'check') {
            return new HealthCheckResult($this, 'critical', 'Storage read/write failed');
        }
        
        $disk->delete('health.txt');
        return new HealthCheckResult($this, 'ok');
    }

    public function getName(): string
    {
        return 'storage';
    }
}

class QueueHealthCheck extends HealthCheck
{
    public function run(): HealthCheckResult
    {
        try {
            Queue::size();
            return new HealthCheckResult($this, 'ok');
        } catch (\Exception $e) {
            return new HealthCheckResult($this, 'critical', $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'queue';
    }
}

class MemoryHealthCheck extends HealthCheck
{
    public function run(): HealthCheckResult
    {
        $usage = memory_get_usage(true);
        $limit = $this->getMemoryLimit();
        
        $percentage = ($usage / $limit) * 100;
        
        if ($percentage > 90) {
            return new HealthCheckResult($this, 'critical', 'Memory usage above 90%');
        }
        
        if ($percentage > 75) {
            return new HealthCheckResult($this, 'warning', 'Memory usage above 75%');
        }
        
        return new HealthCheckResult($this, 'ok');
    }

    public function getName(): string
    {
        return 'memory';
    }

    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $limit, $matches)) {
            $value = $matches[1];
            $unit = strtolower($matches[2]);
            
            switch ($unit) {
                case 'g': $value *= 1024;
                case 'm': $value *= 1024;
                case 'k': $value *= 1024;
            }
            
            return $value;
        }
        
        return PHP_INT_MAX;
    }
}

class CpuHealthCheck extends HealthCheck
{
    public function run(): HealthCheckResult
    {
        $load = sys_getloadavg()[0];
        
        if ($load > 0.9) {
            return new HealthCheckResult($this, 'critical', 'CPU load above 90%');
        }
        
        if ($load > 0.75) {
            return new HealthCheckResult($this, 'warning', 'CPU load above 75%');
        }
        
        return new HealthCheckResult($this, 'ok');
    }

    public function getName(): string
    {
        return 'cpu';
    }
}
