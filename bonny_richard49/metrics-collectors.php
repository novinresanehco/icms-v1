// app/Core/Metrics/Collectors/SystemMetricsCollector.php
<?php

namespace App\Core\Metrics\Collectors;

use App\Core\Metrics\MetricsCollector;

class SystemMetricsCollector
{
    private MetricsCollector $metrics;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function collect(): void
    {
        $this->collectMemoryMetrics();
        $this->collectCPUMetrics();
        $this->collectDiskMetrics();
    }

    private function collectMemoryMetrics(): void
    {
        $memInfo = $this->getMemoryInfo();
        
        $this->metrics->gauge('memory_total_bytes', $memInfo['total']);
        $this->metrics->gauge('memory_free_bytes', $memInfo['free']);
        $this->metrics->gauge('memory_used_bytes', $memInfo['total'] - $memInfo['free']);
    }

    private function collectCPUMetrics(): void
    {
        $cpuInfo = $this->getCPUInfo();
        
        $this->metrics->gauge('cpu_usage_percent', $cpuInfo['usage']);
        $this->metrics->gauge('cpu_load_1min', $cpuInfo['load1']);
        $this->metrics->gauge('cpu_load_5min', $cpuInfo['load5']);
        $this->metrics->gauge('cpu_load_15min', $cpuInfo['load15']);
    }

    private function collectDiskMetrics(): void
    {
        $diskInfo = $this->getDiskInfo();
        
        $this->metrics->gauge('disk_total_bytes', $diskInfo['total']);
        $this->metrics->gauge('disk_free_bytes', $diskInfo['free']);
        $this->metrics->gauge('disk_used_bytes', $diskInfo['used']);
    }

    private function getMemoryInfo(): array
    {
        $memInfo = file_get_contents('/proc/meminfo');
        preg_match_all('/(\w+):\s+(\d+)/', $memInfo, $matches);
        
        $info = array_combine($matches[1], $matches[2]);
        
        return [
            'total' => $info['MemTotal'] * 1024,
            'free' => $info['MemFree'] * 1024
        ];
    }

    private function getCPUInfo(): array
    {
        $load = sys_getloadavg();
        
        return [
            'usage' => $this->getCPUUsage(),
            'load1' => $load[0],
            'load5' => $load[1],
            'load15' => $load[2]
        ];
    }

    private function getCPUUsage(): float
    {
        $stat = file_get_contents('/proc/stat');
        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $matches);
        
        $total = array_sum(array_slice($matches, 1));
        $idle = $matches[4];
        
        return 100 * (1 - $idle / $total);
    }

    private function getDiskInfo(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        
        return [
            'total' => $total,
            'free' => $free,
            'used' => $total - $free
        ];
    }
}

// app/Core/Metrics/Collectors/DatabaseMetricsCollector.php
<?php

namespace App\Core\Metrics\Collectors;

use App\Core\Metrics\MetricsCollector;
use Illuminate\Support\Facades\DB;

class DatabaseMetricsCollector
{
    private MetricsCollector $metrics;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function collect(): void
    {
        $this->collectConnectionMetrics();
        $this->collectQueryMetrics();
        $this->collectTableMetrics();
    }

    private function collectConnectionMetrics(): void
    {
        $connections = DB::getConnections();
        
        $this->metrics->gauge('db_connections_total', count($connections));
        $this->metrics->gauge('db_connections_active', $this->getActiveConnections());
    }

    private function collectQueryMetrics(): void
    {
        $stats = DB::getQueryLog();
        
        $this->metrics->gauge('db_queries_total', count($stats));
        $this->metrics->gauge('db_queries_slow', $this->getSlowQueries($stats));
        $this->metrics->timing('db_query_duration_avg', $this->getAverageQueryTime($stats));
    }

    private function collectTableMetrics(): void
    {
        foreach ($this->getTables() as $table) {
            $size = $this->getTableSize($table);
            $rows = $this->getTableRows($table);
            
            $this->metrics->gauge("db_table_size_bytes.$table", $size);
            $this->metrics->gauge("db_table_rows.$table", $rows);
        }
    }

    private function getActiveConnections(): int
    {
        return DB::select('SHOW PROCESSLIST')->count();
    }

    private function getSlowQueries(array $stats): int
    {
        return count(array_filter($stats, fn($query) => $query['time'] > 1000));
    }

    private function getAverageQueryTime(array $stats): float
    {
        if (empty($stats)) {
            return 0;
        }
        
        $total = array_sum(array_column($stats, 'time'));
        return $total / count($stats);
    }

    private function getTables(): array
    {
        return DB::select('SHOW TABLES');
    }

    private function getTableSize(string $table): int
    {
        $result = DB::select("
            SELECT 
                data_length + index_length as size 
            FROM information_schema.tables 
            WHERE table_schema = ? AND table_name = ?
        ", [DB::getDatabaseName(), $table]);

        return $result[0]->size ?? 0;
    }

    private function getTableRows(string $table): int
    {
        $result = DB::select("SELECT COUNT(*) as count FROM $table");
        return $result[0]->count ?? 0;
    }
}

// app/Core/Metrics/Collectors/CacheMetricsCollector.php
<?php

namespace App\Core\Metrics\Collectors;

use App\Core\Metrics\MetricsCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheMetricsCollector
{
    private MetricsCollector $metrics;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function collect(): void
    {
        $this->collectHitRateMetrics();
        $this->collectMemoryMetrics();
        $this->collectKeyspaceMetrics();
    }

    private function collectHitRateMetrics(): void
    {
        $info = Redis::info();
        
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        $hitRate = $total > 0 ? ($hits / $total) * 100 : 0;
        
        $this->metrics->gauge('cache_hits_total', $hits);
        $this->metrics->gauge('cache_misses_total', $misses);
        $this->metrics->gauge('cache_hit_rate', $hitRate);
    }

    private function collectMemoryMetrics(): void
    {
        $info = Redis::info('memory');
        
        $this->metrics->gauge('cache_memory_used_bytes', $info['used_memory'] ?? 0);
        $this->metrics->gauge('cache_memory_peak_bytes', $info['used_memory_peak'] ?? 0);
        $this->metrics->gauge('cache_memory_fragmentation', $info['mem_fragmentation_ratio'] ?? 0);
    }

    private function collectKeyspaceMetrics(): void
    {
        $info = Redis::info('keyspace');
        
        foreach ($info as $db => $stats) {
            preg_match('/keys=(\d+),expires=(\d+)/', $stats, $matches);
            
            if (isset($matches[1], $matches[2])) {
                $this->metrics->gauge("cache_keys_total.$db", $matches[1]);
                $this->metrics->gauge("cache_keys_expired.$db", $matches[2]);
            }
        }
    }
}