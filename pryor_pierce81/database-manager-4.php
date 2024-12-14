<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\DatabaseMonitorInterface;
use App\Core\Exception\{DatabaseException, SecurityException};
use Psr\Log\LoggerInterface;

class DatabaseManager implements DatabaseManagerInterface
{
    private $connection;
    private SecurityManagerInterface $security;
    private DatabaseMonitorInterface $monitor;
    private LoggerInterface $logger;
    private array $config;
    private bool $inTransaction = false;

    public function __construct(
        ConnectionInterface $connection,
        SecurityManagerInterface $security,
        DatabaseMonitorInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->connection = $connection;
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function query(string $sql, array $params = [], array $context = []): QueryResult
    {
        $queryId = $this->monitor->startQuery($sql, $params);

        try {
            $this->security->validateAccess('database:query', $sql, $context);
            $this->validateQuery($sql, $params);

            $statement = $this->connection->prepare($sql);
            $this->bindParameters($statement, $params);

            $result = $statement->execute();
            $this->monitor->querySuccess($queryId, $result);

            return new QueryResult($result, $statement->rowCount());

        } catch (\Exception $e) {
            $this->monitor->queryFailure($queryId, $e);
            $this->logger->error('Query failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException('Query failed', 0, $e);
        }
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new DatabaseException('Transaction already in progress');
        }

        $this->connection->beginTransaction();
        $this->inTransaction = true;
        $this->monitor->transactionStart();
    }

    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new DatabaseException('No transaction in progress');
        }

        try {
            $this->connection->commit();
            $this->inTransaction = false;
            $this->monitor->transactionCommit();

        } catch (\Exception $e) {
            $this->logger->error('Transaction commit failed', [
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException('Commit failed', 0, $e);
        }
    }

    public function rollback(): void
    {
        if (!$this->inTransaction) {
            throw new DatabaseException('No transaction in progress');
        }

        try {
            $this->connection->rollBack();
            $this->inTransaction = false;
            $this->monitor->transactionRollback();

        } catch (\Exception $e) {
            $this->logger->error('Transaction rollback failed', [
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException('Rollback failed', 0, $e);
        }
    }

    private function validateQuery(string $sql, array $params): void
    {
        if (strlen($sql) > $this->config['max_query_length']) {
            throw new DatabaseException('Query too long');
        }

        if (!$this->isQueryAllowed($sql)) {
            throw new SecurityException('Query type not allowed');
        }

        foreach ($params as $value) {
            if (strlen($value) > $this->config['max_param_length']) {
                throw new DatabaseException('Parameter too long');
            }
        }
    }

    private function bindParameters($statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = $this->getParamType($value);
            $statement->bindValue($key, $value, $type);
        }
    }

    private function isQueryAllowed(string $sql): bool
    {
        $type = strtoupper(substr(trim($sql), 0, 6));
        return in_array($type, $this->config['allowed_queries']);
    }

    private function getParamType($value): int
    {
        return match(gettype($value)) {
            'integer' => \PDO::PARAM_INT,
            'boolean' => \PDO::PARAM_BOOL,
            'NULL' => \PDO::PARAM_NULL,
            default => \PDO::PARAM_STR
        };
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_query_length' => 10000,
            'max_param_length' => 1000,
            'allowed_queries' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE']
        ];
    }
}
