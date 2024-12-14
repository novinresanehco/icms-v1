<?php

namespace App\Core\Monitoring\Database;

class DatabaseMonitor
{
    private ConnectionManager $connectionManager;
    private QueryAnalyzer $queryAnalyzer;
    private PerformanceCollector $performanceCollector;
    private HealthChecker $healthChecker;
    private AlertManager $alertManager;
    private ReplicationMonitor $replicationMonitor;

    public function monitor(): DatabaseStatus
    {
        $status = [];
        $replicationStatus = null;

        foreach ($this->connectionManager->getConnections() as $connection) {
            $health = $this->healthChecker->check($connection);
            $performance = $this->performanceCollector->collect($connection);
            $queryStats = $this->queryAnalyzer->analyze($connection);

            $status[$connection->getName()] = new ConnectionStatus(
                $connection,
                $health,
                $performance,
                $queryStats
            );

            if ($status[$connection->getName()]->hasIssues()) {
                $this->alertManager->notify(
                    new DatabaseAlert($status[$connection->getName()])
                );
            }
        }

        if ($this->connectionManager->hasReplication()) {
            $replicationStatus = $this->replicationMonitor->monitor();
        }

        return new DatabaseStatus($status, $replicationStatus);
    }
}

class QueryAnalyzer
{
    private QueryProfiler $profiler;
    private TableAnalyzer $tableAnalyzer;
    private IndexAnalyzer $indexAnalyzer;
    private LockAnalyzer $lockAnalyzer;

    public function analyze(Connection $connection): QueryAnalysis
    {
        return new QueryAnalysis([
            'slow_queries' => $this->profiler->getSlowQueries($connection),
            'table_stats' => $this->tableAnalyzer->analyze($connection),
            'index_stats' => $this->indexAnalyzer->analyze($connection),
            'locks' => $this->lockAnalyzer->analyze($connection)
        ]);
    }
}

class PerformanceCollector
{
    private MetricsCollector $metricsCollector;
    private ResourceMonitor $resourceMonitor;
    private CacheAnalyzer $cacheAnalyzer;
    private ConnectionAnalyzer $connectionAnalyzer;

    public function collect(Connection $connection): DatabaseMetrics
    {
        return new DatabaseMetrics([
            'metrics' => $this->metricsCollector->collect($connection),
            'resources' => $this->resourceMonitor->monitor($connection),
            'cache' => $this->cacheAnalyzer->analyze($connection),
            'connections' => $this->connectionAnalyzer->analyze($connection)
        ]);
    }
}

class HealthChecker
{
    private ConnectionTester $connectionTester;
    private ReplicationChecker $replicationChecker;
    private IntegrityChecker $integrityChecker;
    private BackupValidator $backupValidator;

    public function check(Connection $connection): HealthStatus
    {
        $issues = [];

        try {
            if (!$this->connectionTester->test($connection)) {
                $issues[] = new HealthIssue('connection', 'Connection test failed');
            }

            if ($connection->isReplica() && !$this->replicationChecker->check($connection)) {
                $issues[] = new HealthIssue('replication', 'Replication check failed');
            }

            $integrityIssues = $this->integrityChecker->check($connection);
            if (!empty($integrityIssues)) {
                $issues = array_merge($issues, $integrityIssues);
            }

            $backupIssues = $this->backupValidator->validate($connection);
            if (!empty($backupIssues)) {
                $issues = array_merge($issues, $backupIssues);
            }
        } catch (\Exception $e) {
            $issues[] = new HealthIssue('check_failure', $e->getMessage());
        }

        return new HealthStatus($issues);
    }
}

class ReplicationMonitor
{
    private ReplicationStatusChecker $statusChecker;
    private LagMonitor $lagMonitor;
    private ConsistencyChecker $consistencyChecker;
    private AlertManager $alertManager;

    public function monitor(): ReplicationStatus
    {
        $status = $this->statusChecker->check();
        $lag = $this->lagMonitor->check();
        $consistency = $this->consistencyChecker->check();

        $replicationStatus = new ReplicationStatus($status, $lag, $consistency);

        if ($replicationStatus->hasIssues()) {
            $this->alertManager->notifyReplicationIssue($replicationStatus);
        }

        return $replicationStatus;
    }
}

class DatabaseStatus
{
    private array $connectionStatus;
    private ?ReplicationStatus $replicationStatus;
    private float $timestamp;

    public function __construct(array $connectionStatus, ?ReplicationStatus $replicationStatus)
    {
        $this->connectionStatus = $connectionStatus;
        $this->replicationStatus = $replicationStatus;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        foreach ($this->connectionStatus as $status) {
            if ($status->hasIssues()) {
                return true;
            }
        }

        return $this->replicationStatus?->hasIssues() ?? false;
    }

    public function getConnectionStatus(string $name): ?ConnectionStatus
    {
        return $this->connectionStatus[$name] ?? null;
    }

    public function getReplicationStatus(): ?ReplicationStatus
    {
        return $this->replicationStatus;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class ConnectionStatus
{
    private Connection $connection;
    private HealthStatus $health;
    private DatabaseMetrics $performance;
    private QueryAnalysis $queryStats;

    public function __construct(
        Connection $connection,
        HealthStatus $health,
        DatabaseMetrics $performance,
        QueryAnalysis $queryStats
    ) {
        $this->connection = $connection;
        $this->health = $health;
        $this->performance = $performance;
        $this->queryStats = $queryStats;
    }

    public function hasIssues(): bool
    {
        return $this->health->hasIssues() || 
               $this->performance->hasIssues() || 
               $this->queryStats->hasIssues();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getHealth(): HealthStatus
    {
        return $this->health;
    }

    public function getPerformance(): DatabaseMetrics
    {
        return $this->performance;
    }

    public function getQueryStats(): QueryAnalysis
    {
        return $this->queryStats;
    }
}
