<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{DatabaseException, SecurityException};
use Illuminate\Support\Facades\DB;

class DatabaseManager implements DatabaseManagerInterface
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private CacheManager $cache;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function executeQuery(DatabaseQuery $query, SecurityContext $context): QueryResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($query, $context) {
                $validatedQuery = $this->validateQuery($query);
                $secureQuery = $this->prepareSecureQuery($validatedQuery);
                
                $this->beginTransaction();
                
                try {
                    $result = $this->executeSecureQuery($secureQuery);
                    $this->validateQueryResult($result);
                    
                    $this->commitTransaction();
                    return $result;
                } catch (\Exception $e) {
                    $this->rollbackTransaction();
                    throw $e;
                }
            },
            $context
        );
    }

    public function createRepository(string $model, SecurityContext $context): RepositoryInterface
    {
        return $this->protection->executeProtectedOperation(
            function() use ($model, $context) {
                $validatedModel = $this->validateModel($model);
                $config = $this->loadRepositoryConfig($validatedModel);
                
                return $this->initializeRepository($config);
            },
            $context
        );
    }

    private function validateQuery(DatabaseQuery $query): DatabaseQuery
    {
        if (!$this->validator->validateQuery($query)) {
            throw new DatabaseException('Invalid query structure');
        }

        if ($query->isUnsafe()) {
            throw new SecurityException('Unsafe query detected');
        }

        return $query;
    }

    private function prepareSecureQuery(DatabaseQuery $query): SecureQuery
    {
        return new SecureQuery(
            $query,
            $this->security->getQuerySecurity()
        );
    }

    private function executeSecureQuery(SecureQuery $query): QueryResult
    {
        $this->metrics->startQuery($query);
        
        try {
            $statement = $this->prepareStatement($query);
            $result = $this->executeStatement($statement);
            
            $this->auditQueryExecution($query, $result);
            return $result;
            
        } finally {
            $this->metrics->endQuery($query);
        }
    }

    private function validateQueryResult(QueryResult $result): void
    {
        if (!$this->validator->validateQueryResult($result)) {
            throw new DatabaseException('Query result validation failed');
        }

        $this->validateResultSecurity($result);
    }

    private function beginTransaction(): void
    {
        if (!DB::beginTransaction()) {
            throw new DatabaseException('Failed to start transaction');
        }

        $this->metrics->incrementActiveTransactions();
    }

    private function commitTransaction(): void
    {
        DB::commit();
        $this->metrics->decrementActiveTransactions();
    }

    private function rollbackTransaction(): void
    {
        DB::rollBack();
        $this->metrics->decrementActiveTransactions();
    }

    private function validateModel(string $model): string
    {
        if (!$this->validator->validateModelClass($model)) {
            throw new DatabaseException('Invalid model class');
        }

        if (!$this->security->validateModelSecurity($model)) {
            throw new SecurityException('Model security validation failed');
        }

        return $model;
    }

    private function initializeRepository(RepositoryConfig $config): RepositoryInterface
    {
        $repository = new SecureRepository($config);
        
        $repository->setCache($this->cache);
        $repository->setSecurity($this->security);
        $repository->setMetrics($this->metrics);
        
        return $repository;
    }

    private function prepareStatement(SecureQuery $query): PreparedStatement
    {
        $statement = new PreparedStatement($query);
        
        $this->validateStatement($statement);
        $this->optimizeStatement($statement);
        
        return $statement;
    }

    private function executeStatement(PreparedStatement $statement): QueryResult
    {
        $result = $statement->execute();
        
        if ($result->hasError()) {
            throw new DatabaseException(
                'Statement execution failed: ' . $result->getError()
            );
        }

        return $result;
    }

    private function validateResultSecurity(QueryResult $result): void
    {
        if ($result->containsSensitiveData()) {
            $this->security->validateDataAccess($result);
        }
    }

    private function auditQueryExecution(SecureQuery $query, QueryResult $result): void
    {
        $this->security->auditQuery([
            'query' => $query->getSanitized(),
            'parameters' => $query->getParameters(),
            'result_count' => $result->count(),
            'execution_time' => $this->metrics->getLastQueryTime(),
            'timestamp' => now()
        ]);
    }
}
