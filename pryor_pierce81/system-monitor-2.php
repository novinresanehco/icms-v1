<?php

namespace App\Core\Monitoring\System;

class SystemMonitor
{
    private ResourceMonitor $resourceMonitor;
    private ProcessMonitor $processMonitor;
    private NetworkMonitor $networkMonitor;
    private StorageMonitor $storageMonitor;
    private AlertManager $alertManager;

    public function monitor(): SystemStatus
    {
        $resources = $this->resourceMonitor->monitor();
        $processes = $this->processMonitor->monitor();
        $network = $this->networkMonitor->monitor();
        $storage = $this->storageMonitor->monitor();

        $status = new SystemStatus($resources, $processes, $network, $storage);

        if ($status->hasIssues()) {
            $this->alertManager->notify(new SystemAlert($status));
        }

        return $status;
    }
}

class ResourceMonitor
{
    private CpuMonitor $cpuMonitor;
    private MemoryMonitor $memoryMonitor;
    private LoadMonitor $loadMonitor;

    public function monitor(): ResourceMetrics
    {
        return new ResourceMetrics([
            'cpu' => $this->cpuMonitor->monitor(),
            'memory' => $this->memoryMonitor->monitor(),
            'load' => $this->loadMonitor->monitor()
        ]);
    }
}

class ProcessMonitor
{
    private ProcessCollector $collector;
    private ProcessAnalyzer $analyzer;
    private ResourceCalculator $calculator;

    public function monitor(): ProcessMetrics
    {
        $processes = $this->collector->collect();
        $analysis = $this->analyzer->analyze($processes);
        $resources = $this->calculator->calculate($processes);

        return new ProcessMetrics($processes, $analysis, $resources);
    }
}

class NetworkMonitor
{
    private InterfaceMonitor $interfaceMonitor;
    private BandwidthMonitor $bandwidthMonitor;
    private ConnectionMonitor $connectionMonitor;

    public function monitor(): NetworkMetrics
    {
        return new NetworkMetrics([
            'interfaces' => $this->interfaceMonitor->monitor(),
            'bandwidth' => $this->bandwidthMonitor->monitor(),
            'connections' => $this->connectionMonitor->monitor()
        ]);
    }
}

class StorageMonitor
{
    private DiskMonitor $diskMonitor;
    private IoMonitor $ioMonitor;
    private FileSystemMonitor $fsMonitor;

    public function monitor(): StorageMetrics
    {
        return new StorageMetrics([
            'disks' => $this->diskMonitor->monitor(),
            'io' => $this->ioMonitor->monitor(),
            'filesystem' => $this->fsMonitor->monitor()
        ]);
    }
}

class SystemStatus
{
    private ResourceMetrics $resources;
    private ProcessMetrics $processes;
    private NetworkMetrics $network;
    private StorageMetrics $storage;
    private float $timestamp;

    public function __construct(
        ResourceMetrics $resources,
        ProcessMetrics $processes,
        NetworkMetrics $network,
        StorageMetrics $storage
    ) {
        $this->resources = $resources;
        $this->processes = $processes;
        $this->network = $network;
        $this->storage = $storage;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->resources->hasIssues() ||
               $this->processes->hasIssues() ||
               $this->network->hasIssues() ||
               $this->storage->hasIssues();
    }

    public function getResources(): ResourceMetrics
    {
        return $this->resources;
    }

    public function getProcesses(): ProcessMetrics
    {
        return $this->processes;
    }

    public function getNetwork(): NetworkMetrics
    {
        return $this->network;
    }

    public function getStorage(): StorageMetrics
    {
        return $this->storage;
    }
}

class ResourceMetrics
{
    private array $metrics;
    private array $thresholds;
    private array $alerts;

    public function __construct(array $metrics)
    {
        $this->metrics = $metrics;
        $this->thresholds = $this->calculateThresholds();
        $this->alerts = $this->checkAlerts();
    }

    public function hasIssues(): bool
    {
        return !empty($this->alerts);
    }

    private function calculateThresholds(): array
    {
        $thresholds = [];
        foreach ($this->metrics as $type => $metric) {
            $thresholds[$type] = $this->getThresholdFor($type, $metric);
        }
        return $thresholds;
    }

    private function checkAlerts(): array
    {
        $alerts = [];
        foreach ($this->metrics as $type => $metric) {
            if ($metric > $this->thresholds[$type]) {
                $alerts[] = new ResourceAlert($type, $metric, $this->thresholds[$type]);
            }
        }
        return $alerts;
    }
}

class ProcessMetrics
{
    private array $processes;
    private ProcessAnalysis $analysis;
    private ResourceUsage $resources;

    public function __construct(array $processes, ProcessAnalysis $analysis, ResourceUsage $resources)
    {
        $this->processes = $processes;
        $this->analysis = $analysis;
        $this->resources = $resources;
    }

    public function hasIssues(): bool
    {
        return $this->analysis->hasIssues() || $this->resources->exceedsLimits();
    }

    public function getProcesses(): array
    {
        return $this->processes;
    }

    public function getAnalysis(): ProcessAnalysis
    {
        return $this->analysis;
    }

    public function getResources(): ResourceUsage
    {
        return $this->resources;
    }
}

class NetworkMetrics
{
    private array $metrics;
    private float $timestamp;

    public function __construct(array $metrics)
    {
        $this->metrics = $metrics;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        foreach ($this->metrics as $metric) {
            if ($metric['status'] === 'error') {
                return true;
            }
        }
        return false;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class StorageMetrics
{
    private array $metrics;
    private array $thresholds;

    public function __construct(array $metrics)
    {
        $this->metrics = $metrics;
        $this->thresholds = $this->defineThresholds();
    }

    public function hasIssues(): bool
    {
        foreach ($this->metrics as $type => $metric) {
            if ($metric > $this->thresholds[$type]) {
                return true;
            }
        }
        return false;
    }

    private function defineThresholds(): array
    {
        return [
            'disk_usage' => 90,  // 90% usage threshold
            'io_wait' => 5,      // 5% IO wait threshold
            'inode_usage' => 85  // 85% inode usage threshold
        ];
    }
}
