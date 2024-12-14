<?php

namespace App\Core\Database;

class CriticalDatabaseService
{
    private $connection;
    private $monitor;
    private $security;

    public function execute(string $query, array $params = []): Result
    {
        $operationId = $this->monitor->startDatabaseOperation();

        try {
            // Validate query
            $this->security->validateQuery($query, $params);

            // Start transaction
            $this->connection->beginTransaction();

            // Execute with monitoring
            $result = $this->connection->execute($query, $params);

            // Verify result integrity
            $this->verifyResult($result);

            $this->connection->commit();
            $this->monitor->trackSuccess($operationId);

            return $result;

        } catch (\Exception $e) {
            $this->connection->rollBack();
            $this->handleDatabaseError($e, $operationId);
            throw $e;
        }
    }

    private function verifyResult(Result $result): void
    {
        if (!$result->isValid()) {
            throw new DataIntegrityException('Result validation failed');
        }
    }

    private function handleDatabaseError(\Exception $e, string $operationId): void
    {
        $this->monitor->trackFailure($operationId, $e);
        if ($this->isCriticalError($e)) {
            $this->monitor->triggerAlert('DATABASE_CRITICAL_ERROR');
        }
    }
}
