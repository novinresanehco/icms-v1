<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\DatabaseException;
use Psr\Log\LoggerInterface;

class DatabaseManager implements DatabaseManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $connections = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function executeQuery(string $query, array $params = []): QueryResult
    {
        $queryId = $this->generateQueryId();

        try {
            $this->security->validateSecureOperation('database:query', [
                'query_type' => $this->getQueryType($query)
            ]);

            $this->validateQuery($query);
            $this->validateQueryParams($params);

            $connection = $this->getConnection();
            $this->beginQueryTransaction($connection);

            $result = $this->processQuery($connection, $query, $params);
            $this->validateQueryResult($result);

            $this->commitQueryTransaction($connection);
            $this->logQueryExecution($queryId, $query, $params);

            return $result;

        } catch (\Exception $e) {
            $this->rollbackQueryTransaction($connection ?? null);
            $this->handleQueryFailure($queryId, $query, $e);
            throw new DatabaseException('Query execution failed', 0, $e);
        }
    }

    public function beginTransaction(): string
    {
        $transactionId = $this->generateTransactionId();

        try {
            $this->security->validateSecureOperation('database:transaction', []);
            
            $connection = $this->getConnection();
            $connection->beginTransaction();

            $this->activeTransactions[$transactionId] = $connection;
            $this->logTransactionStart($transactionId);

            return $transactionId;

        } catch (\Exception $e) {
            $this->handleTransactionFailure($transactionId, 'begin', $e);
            throw new DatabaseException('Transaction start failed', 0, $e);
        }
    }

    public function commitTransaction(string $transactionId): void
    {
        try {
            $this->security->validateSecureOperation('database:commit', [
                'transaction_id' => $transactionId
            ]);

            $connection = $this->getTransactionConnection($transactionId);
            $connection->commit();

            unset($this->activeTransactions[$transactionId]);
            $this->logTransactionCommit($transactionId);

        } catch (\Exception $e) {
            $this->handleTransactionFailure($transactionId, 'commit', $e);
            throw new DatabaseException('Transaction commit failed', 0, $e);
        }
    }

    private function validateQuery(string $query): void
    {
        if (empty(trim($query))) {
            throw new DatabaseException('Empty query string');
        }

        if (!$this->isQueryAllowed($query)) {
            throw new DatabaseException('Query type not allowed');
        }

        if (!$this->validateQuerySyntax($query)) {
            throw new DatabaseException('Invalid query syntax');
        }
    }

    private function validateQueryParams(array $params): void
    {
        foreach ($params as $key => $value) {
            if (!$this->isValidParamType($value)) {
                throw new DatabaseException("Invalid parameter type for key: {$key}");
            }

            if (!$this->isValidParamValue($value)) {
                throw new DatabaseException("Invalid parameter value for key: {$key}");
            }
        }
    }

    private function processQuery(Connection $connection, string $query, array $params): QueryResult
    {
        $statement = $connection->prepare($query);
        $statement->execute($params);

        return new QueryResult(
            $statement->fetchAll(),
            $statement->rowCount(),
            $this->getQueryMetrics($statement)
        );
    }

    private function handleQueryFailure(string $id, string $query, \Exception $e): void
    {
        $this->logger->error('Query execution failed', [
            'query_id' => $id,
            'query' => $query,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyQueryFailure($id, $query, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_connections' => 100,
            'connection_timeout' => 30,
            'query_timeout' => 60,
            'max_query_length' => 10000,
            'allowed_query_types' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            'transaction_timeout' => 300
        ];
    }
}
