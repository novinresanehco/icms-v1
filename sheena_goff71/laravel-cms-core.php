<?php

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Interfaces\{SecurityInterface, ValidationInterface};

class CoreSecurityManager implements SecurityInterface
{
    private ValidationService $validator;
    private AuditService $audit;

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->monitorExecution($operation);
            
            // Verify result
            $this->validateResult($result);
            
            DB::commit();
            
            // Log success
            $this->audit->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    protected function validateOperation(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }
}

class ValidationService implements ValidationInterface
{
    public function validateContext(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (!$this->validateField($key, $value)) {
                return false;
            }
        }
        return true;
    }

    public function validateResult($result): bool
    {
        return !is_null($result) && $this->validateStructure($result);
    }

    protected function validateField(string $key, $value): bool
    {
        return match($key) {
            'user_id' => is_numeric($value),
            'action' => in_array($value, ['create', 'update', 'delete']),
            'data' => is_array($value),
            default => true
        };
    }
}

abstract class BaseRepository
{
    protected $model;
    protected $cache;

    public function find(int $id)
    {
        return Cache::remember(
            $this->getCacheKey($id),
            config('cache.ttl'),
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data)
    {
        return DB::transaction(function() use ($data) {
            $model = $this->model->create($data);
            Cache::forget($this->getCacheKey($model->id));
            return $model;
        });
    }

    protected function getCacheKey($id): string
    {
        return sprintf('%s:%s', $this->model->getTable(), $id);
    }
}

trait AuditLogging
{
    protected function logActivity(string $action, array $data = []): void
    {
        Log::info("CMS Activity: {$action}", [
            'user_id' => auth()->id(),
            'action' => $action,
            'data' => $data,
            'ip' => request()->ip(),
            'timestamp' => now()
        ]);
    }
}
