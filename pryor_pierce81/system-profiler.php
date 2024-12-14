<?php

namespace App\Core\Monitoring\System;

class SystemProfiler {
    private ResourceMonitor $resourceMonitor;
    private ProcessAnalyzer $processAnalyzer;
    private NetworkAnalyzer $networkAnalyzer;
    private DiskAnalyzer $diskAnalyzer;
    private AlertManager $alertManager;

    public function __construct(
        ResourceMonitor $resourceMonitor,
        ProcessAnalyzer $processAnalyzer,
        NetworkAnalyzer $networkAnalyzer,
        DiskAnalyzer $diskAnalyzer,
        AlertManager $alertManager
    ) {
        $this->resourceMonitor = $resourceMonitor;
        $this->processAnalyzer = $processAnalyzer;
        $this->networkAnalyzer = $networkAnalyzer;
        $this->diskAnalyzer = $diskAnalyzer;
        $this->alertManager = $alertManager;
    }

    public function profile(): SystemProfile 
    {
        $resources = $this->resourceMonitor->monitor();
        $processes = $this->processAnalyzer->analyze();
        $network = $this->networkAnalyzer->analyze();
        $disk = $this->diskAnalyzer->analyze();

        $issues = $this->detectIssues($resources, $processes, $network, $disk);
        
        if (!empty($issues)) {
            $this->alertManager->notify(new SystemAlert($issues));
        }

        return new SystemProfile($resources, $processes, $network, $disk);
    }

    private function detectIssues(
        ResourceMetrics $resources,
        ProcessAnalysis $processes,
        NetworkAnalysis $network,
        DiskAnalysis $disk
    ): array {
        $issues = [];

        if ($resources->hasHighUtilization()) {
            $issues[] = new SystemIssue('high_resource_utilization', $resources->getUtilizationData());
        }

        if ($processes->hasBlockedProcesses()) {
            $issues[] = new SystemIssue('blocked_processes', $processes->getBlockedProcesses());
        }

        if ($network->hasBottlenecks()) {
            $issues[] = new SystemIssue('network_bottleneck', $network->getBottleneckData());
        }

        if ($disk->hasIoIssues()) {
            $issues[] = new SystemIssue('disk_io_issues', $disk->getIoData());
        }

        return $issues;
    }
}

class ResourceMonitor {
    private array $thresholds;

    public function monitor(): ResourceMetrics 
    {
        $metrics = [
            'cpu' => $this->getCpuMetrics(),
            'memory' => $this->getMemoryMetrics(),
            'load' => $this->getLoadMetrics(),
            'io' => $this->getIoMetrics()
        ];

        return new ResourceMetrics($metrics);
    }

    private function getCpuMetrics(): array 
    {
        $stats = sys_getloadavg();
        $cpuInfo = $this->getCpuInfo();

        return [
            'load_average' => [
                '1min' => $stats[0],
                '5min' => $stats[1],
                '15min' => $stats[2]
            ],
            'usage' => $cpuInfo['usage'],
            'cores' => $cpuInfo['cores'],
            'frequency' => $cpuInfo['frequency']
        ];
    }

    private function getMemoryMetrics(): array 
    {
        $memory = $this->getMemoryInfo();
        
        return [
            'total' => $memory['total'],
            'used' => $memory['used'],
            'free' => $memory['free'],
            'shared' => $memory['shared'],
            'cached' => $memory['cached'],
            'available' => $memory['available'],
            'swap' => [
                'total' => $memory['swap_total'],
                'used' => $memory['swap_used'],
                'free' => $memory['swap_free']
            ]
        ];
    }

    private function getLoadMetrics(): array 
    {
        return [
            'processes' => $this->getProcessCount(),
            'threads' => $this->getThreadCount(),
            'running' => $this->getRunningProcessCount(),
            'blocked' => $this->getBlockedProcessCount()
        ];
    }

    private function getIoMetrics(): array 
    {
        $io = $this->getIoStats();
        
        return [
            'read_bytes' => $io['read_bytes'],
            'write_bytes' => $io['write_bytes'],
            'read_ops' => $io['read_ops'],
            'write_ops' => $io['write_ops'],
            'queue_length' => $io['queue_length']
        ];
    }
}

class ProcessAnalyzer {
    private ProcessManager $processManager;
    private ResourceCalculator $resourceCalculator;

    public function analyze(): ProcessAnalysis 
    {
        $processes = $this->processManager->getProcessList();
        $analysis = new ProcessAnalysis();

        foreach ($processes as $process) {
            $metrics = $this->analyzeProcess($process);
            $analysis->addProcessMetrics($process->getPid(), $metrics);
        }

        return $analysis;
    }

    private function analyzeProcess(Process $process): array 
    {
        return [
            'cpu' => $this->resourceCalculator->calculateCpuUsage($process),
            'memory' => $this->resourceCalculator->calculateMemoryUsage($process),
            'io' => $this->resourceCalculator->calculateIoUsage($process),
            'state' => $process->getState(),
            'threads' => $process->getThreadCount(),
            'runtime' => $process->getRuntime()
        ];
    }
}

class NetworkAnalyzer {
    private NetworkInterface $networkInterface;
    private TrafficAnalyzer $trafficAnalyzer;
    private array $thresholds;

    public function analyze(): NetworkAnalysis 
    {
        $interfaces = $this->networkInterface->getInterfaces();
        $analysis = new NetworkAnalysis();

        foreach ($interfaces as $interface) {
            $metrics = $this->analyzeInterface($interface);
            $analysis->addInterfaceMetrics($interface->getName(), $metrics);
        }

        $traffic = $this->trafficAnalyzer->analyzeTraffic();
        $analysis->setTrafficAnalysis($traffic);

        return $analysis;
    }

    private function analyzeInterface(NetworkInterface $interface): array 
    {
        return [
            'bandwidth' => [
                'in' => $interface->getIncomingBandwidth(),
                'out' => $interface->getOutgoingBandwidth()
            ],
            'packets' => [
                'in' => $interface->getIncomingPackets(),
                'out' => $interface->getOutgoingPackets()
            ],
            'errors' => [
                'in' => $interface->getIncomingErrors(),
                'out' => $interface->getOutgoingErrors()
            ],
            'drops' => [
                'in' => $interface->getIncomingDrops(),
                'out' => $interface->getOutgoingDrops()
            ]
        ];
    }
}

class DiskAnalyzer {
    private DiskManager $diskManager;
    private IoAnalyzer $ioAnalyzer;

    public function analyze(): DiskAnalysis 
    {
        $disks = $this->diskManager->getDisks();
        $analysis = new DiskAnalysis();

        foreach ($disks as $disk) {
            $metrics = $this->analyzeDisk($disk);
            $analysis->addDiskMetrics($disk->getPath(), $metrics);
        }

        return $analysis;
    }

    private function analyzeDisk(Disk $disk): array 
    {
        return [
            'usage' => [
                'total' => $disk->getTotalSpace(),
                'used' => $disk->getUsedSpace(),
                'free' => $disk->getFreeSpace()
            ],
            'io' => [
                'read' => $this->ioAnalyzer->getReadMetrics($disk),
                'write' => $this->ioAnalyzer->getWriteMetrics($disk)
            ],
            'latency' => [
                'read' => $this->ioAnalyzer->getReadLatency($disk),
                'write' => $this->ioAnalyzer->getWriteLatency($disk)
            ],
            'queue' => $this->ioAnalyzer->getQueueMetrics($disk)
        ];
    }
}

class SystemProfile {
    private ResourceMetrics $resources;
    private ProcessAnalysis $processes;
    private NetworkAnalysis $network;
    private DiskAnalysis $disk;
    private float $timestamp;

    public function __construct(
        ResourceMetrics $resources,
        ProcessAnalysis $processes,
        NetworkAnalysis $network,
        DiskAnalysis $disk
    ) {
        $this->resources = $resources;
        $this->processes = $processes;
        $this->network = $network;
        $this->disk = $disk;
        $this->timestamp = microtime(true);
    }

    public function toArray(): array 
    {
        return [
            'resources' => $this->resources->toArray(),
            'processes' => $this->processes->toArray(),
            'network' => $this->network->toArray(),
            'disk' => $this->disk->toArray(),
            'timestamp' => $this->timestamp,
            'generated_at' => date('Y-m-d H:i:s', (int)$this->timestamp)
        ];
    }
}

