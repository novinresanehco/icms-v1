<?php

namespace App\Core\Integration;

class IntegrationService implements IntegrationServiceInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private EventDispatcher $events;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private array $integrations;

    public function executeOperation(string $service, string $operation, array $data): OperationResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'integration.execute',
                'service' => $service,
                'operation' => $operation
            ]);

            $integration = $this->getIntegration($service);
            $validated = $this->validateOperationData($integration, $operation, $data);
            
            $startTime = microtime(true);
            
            $result = $this->cache->tags(['integration'])->remember(
                $this->getCacheKey($service, $operation, $validated),
                $integration->getCacheDuration($operation),
                fn() => $integration->execute($operation, $validated)
            );

            $this->metrics->recordOperationMetrics(
                $service,
                $operation,
                microtime(true) - $startTime
            );

            $this->events->dispatch(
                new IntegrationOperationCompleted($service, $operation, $result)
            );

            DB::commit();
            return new OperationResult($result);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($service, $operation, $e);
            throw $e;
        }
    }

    public function registerIntegration(string $service, Integration $integration): void 
    {
        $this->security->validateCriticalOperation([
            'action' => 'integration.register',
            'service' => $service
        ]);

        $this->validator->validateIntegration($integration);
        $this->integrations[$service] = $integration;
    }

    public function batchExecute(array $operations): array 
    {
        $results = [];
        $errors = [];

        foreach ($operations as $key => $operation) {
            try {
                $results[$key] = $this->executeOperation(
                    $operation['service'],
                    $operation['operation'],
                    $operation['data']
                );
            } catch (\Exception $e) {
                $errors[$key] = $e;
            }
        }

        if (!empty($errors)) {
            throw new BatchOperationException($errors);
        }

        return $results;
    }

    public function synchronizeData(string $service, string $entity, array $data): SyncResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'integration.sync',
                'service' => $service,
                'entity' => $entity
            ]);

            $integration = $this->getIntegration($service);
            $validated = $this->validator->validateSyncData($data);
            
            $result = $integration->synchronize($entity, $validated);
            
            $this->events->dispatch(
                new DataSynchronized($service, $entity, $result)
            );

            DB::commit();
            return new SyncResult($result);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSyncFailure($service, $entity, $e);
            throw $e;
        }
    }

    public function validateConnection(string $service): bool 
    {
        try {
            $integration = $this->getIntegration($service);
            return $integration->testConnection();
        } catch (\Exception $e) {
            $this->metrics->incrementConnectionFailure($service);
            return false;
        }
    }

    private function getIntegration(string $service): Integration 
    {
        if (!isset($this->integrations[$service])) {
            throw new IntegrationNotFoundException("Integration not found: {$service}");
        }

        return $this->integrations[$service];
    }

    private function validateOperationData(
        Integration $integration,
        string $operation,
        array $data
    ): array {
        $rules = $integration->getValidationRules($operation);
        return $this->validator->validate($data, $rules);
    }

    private function getCacheKey(string $service, string $operation, array $data): string 
    {
        return sprintf(
            'integration.%s.%s.%s',
            $service,
            $operation,
            hash('sha256', json_encode($data))
        );
    }

    private function handleOperationFailure(
        string $service,
        string $operation,
        \Exception $e
    ): void {
        $this->metrics->incrementOperationFailure($service, $operation);
        
        $this->events->dispatch(
            new IntegrationOperationFailed($service, $operation, $e)
        );

        Log::error('Integration operation failed', [
            'service' => $service,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleSyncFailure(
        string $service,
        string $entity,
        \Exception $e
    ): void {
        $this->metrics->incrementSyncFailure($service, $entity);
        
        $this->events->dispatch(
            new DataSyncFailed($service, $entity, $e)
        );

        Log::error('Data synchronization failed', [
            'service' => $service,
            'entity' => $entity,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
