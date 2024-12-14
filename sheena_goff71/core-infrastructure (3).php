<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, DB, Log};

class CoreInfrastructure
{
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected ValidationService $validator;

    public function __construct(
        CacheManager $cache,
        SecurityManager $security, 
        ValidationService $validator
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
    }

    public function executeOperation(string $operation, array $data): mixed
    {
        DB::beginTransaction();
        
        try {
            $this->validator->validateOperation($operation, $data);
            $this->security->validateAccess($operation);

            $cacheKey = $this->resolveCacheKey($operation, $data);
            
            $result = $this->cache->remember($cacheKey, function() use ($operation, $data) {
                return $this->processOperation($operation, $data);
            });

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Operation failed', [
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function processOperation(string $operation, array $data): mixed
    {
        $handler = $this->resolveHandler($operation);
        
        $result = $handler->execute($data);
        
        if ($this->requiresValidation($operation)) {
            $this->validator->validateResult($result);
        }
        
        return $result;
    }

    protected function resolveCacheKey(string $operation, array $data): string
    {
        return sprintf(
            '%s:%s:%s',
            $operation,
            md5(json_encode($data)),
            $this->security->getCurrentUser()->id ?? 'guest'
        );
    }

    protected function resolveHandler(string $operation): OperationHandler
    {
        $handler = config("operations.handlers.{$operation}");
        
        if (!$handler || !class_exists($handler)) {
            throw new OperationException("Invalid operation handler: {$operation}");
        }

        return app($handler);
    }

    protected function requiresValidation(string $operation): bool
    {
        return in_array($operation, config('operations.validate', []));
    }

    public function invalidateCache(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->cache->tags($tag)->flush();
        }
    }

    public function beginMaintenance(): void
    {
        Cache::tags(['system'])->put('maintenance', true, 3600);
        Log::info('System entered maintenance mode');
    }

    public function endMaintenance(): void
    {
        Cache::tags(['system'])->forget('maintenance');
        Log::info('System exited maintenance mode');
    }
}
