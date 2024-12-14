<?php

namespace App\Core\Data;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\{
    DataManagerInterface,
    CacheInterface
};
use App\Core\Exceptions\{
    DataException,
    SecurityException,
    ValidationException
};

class DataManager implements DataManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheInterface $cache;
    private array $config;

    private const CACHE_TTL = 3600;
    private const MAX_BATCH_SIZE = 1000;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheInterface $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function query(string $modelType, array $criteria, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($modelType, $criteria, $context) {
            $this->validateQueryCriteria($criteria);
            
            $cacheKey = $this->generateCacheKey($modelType, $criteria);
            return $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($modelType, $criteria) {
                return $this->executeQuery($modelType, $criteria);
            });
        }, $context);
    }

    public function store(string $modelType, array $data, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($modelType, $data, $context) {
            $this->validateModelData($modelType, $data);
            
            $processedData = $this->preprocessData($modelType, $data);
            $storedData = $this->executeStore($modelType, $processedData);
            
            $this->invalidateModelCache($modelType);
            $this->createAuditTrail('store', $modelType, $storedData, $context);
            
            return $storedData;
        }, $context);
    }

    public function update(string $modelType, int $id, array $data, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($modelType, $id, $data, $context) {
            $existingData = $this->findById($modelType, $id);
            if (!$existingData) {
                throw new DataException('Record not found');
            }
            
            $this->validateModelData($modelType, $data);
            $this->validateVersionControl($existingData, $data);
            
            $processedData = $this->preprocessData($modelType, $data);
            $updatedData = $this->executeUpdate($modelType, $id, $processedData);
            
            $this->invalidateModelCache($modelType);
            $this->createAuditTrail('update', $modelType, $updatedData, $context);
            
            return $updatedData;
        }, $context);
    }

    public function delete(string $modelType, int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(function() use ($modelType, $id, $context) {
            $existingData = $this->findById($modelType, $id);
            if (!$existingData) {
                throw new DataException('Record not found');
            }
            
            $this->validateDeletion($modelType, $existingData);
            $success = $this->executeDelete($modelType, $id);
            
            if ($success) {
                $this->invalidateModelCache($modelType);
                $this->createAuditTrail('delete', $modelType, $existingData, $context);
            }
            
            return $success;
        }, $context);
    }

    public function batchProcess(string $modelType, array $operations, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($modelType, $operations, $context) {
            if (count($operations) > self::MAX_BATCH_SIZE) {
                throw new ValidationException('Batch size exceeds limit');
            }
            
            $results = [];
            DB::beginTransaction();
            
            try {
                foreach ($operations as $operation) {
                    $results[] = $this->processSingleOperation($modelType, $operation, $context);
                }
                
                DB::commit();
                $this->invalidateModelCache($modelType);
                
                return $results;
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context);
    }

    protected function validateQueryCriteria(array $criteria): void
    {
        if (!$this->validator->validateInput($criteria)) {
            throw new ValidationException('Invalid query criteria');
        }

        if ($this->containsSqlInjection($criteria)) {
            throw new SecurityException('Potential SQL injection detected');
        }
    }

    protected function validateModelData(string $modelType, array $data): void
    {
        $rules = $this->config['validation_rules'][$modelType] ?? null;
        if (!$rules) {
            throw new ValidationException('No validation rules defined for model');
        }

        if (!$this->validator->validateInput($data, $rules)) {
            throw new ValidationException('Invalid model data');
        }
    }

    protected function validateVersionControl(array $existing, array $new): void
    {
        if (isset($existing['version']) && 
            isset($new['version']) && 
            $existing['version'] !== $new['version']
        ) {
            throw new DataException('Version mismatch');
        }
    }

    protected function validateDeletion(string $modelType, array $data): void
    {
        if (!$this->isDeletionAllowed($modelType, $data)) {
            throw new DataException('Deletion not allowed');
        }
    }

    protected function executeQuery(string $modelType, array $criteria): array
    {
        $table = $this->getTableName($modelType);
        $query = DB::table($table);

        foreach ($criteria as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->get()->toArray();
    }

    protected function executeStore(string $modelType, array $data): array
    {
        $table = $this->getTableName($modelType);
        $id = DB::table($table)->insertGetId($data);
        return $this->findById($modelType, $id);
    }

    protected function executeUpdate(string $modelType, int $id, array $data): array
    {
        $table = $this->getTableName($modelType);
        DB::table($table)->where('id', $id)->update($data);
        return $this->findById($modelType, $id);
    }

    protected function executeDelete(string $modelType, int $id): bool
    {
        $table = $this->getTableName($modelType);
        return DB::table($table)->where('id', $id)->delete() > 0;
    }

    protected function findById(string $modelType, int $id): ?array
    {
        $table = $this->getTableName($modelType);
        return DB::table($table)->find($id);
    }

    protected function preprocessData(string $modelType, array $data): array
    {
        $data = $this->sanitizeData($data);
        $data['updated_at'] = time();
        
        if (!isset($data['id'])) {
            $data['created_at'] = time();
            $data['version'] = 1;
        } else {
            $data['version'] = ($data['version'] ?? 0) + 1;
        }
        
        return $data;
    }

    protected function processSingleOperation(string $modelType, array $operation, array $context): array
    {
        $type = $operation['type'] ?? null;
        $data = $operation['data'] ?? [];
        
        return match($type) {
            'create' => $this->store($modelType, $data, $context),
            'update' => $this->update($modelType, $data['id'], $data, $context),
            'delete' => ['success' => $this->delete($modelType, $data['id'], $context)],
            default => throw new ValidationException('Invalid operation type')
        };
    }

    protected function generateCacheKey(string $modelType, array $criteria): string
    {
        return sprintf(
            'data:%s:%s',
            $modelType,
            hash('sha256', json_encode($criteria))
        );
    }

    protected function invalidateModelCache(string $modelType): void
    {
        $this->cache->tags(['data:' . $modelType])->flush();
    }

    protected function getTableName(string $modelType): string
    {
        return $this->config['table_map'][$modelType] ?? $modelType;
    }

    protected function containsSqlInjection(array $data): bool
    {
        $json = json_encode($data);
        $patterns = [
            '/\bUNION\b/i',
            '/\bSELECT\b/i',
            '/\bDROP\b/i',
            '/\bDELETE\b/i',
            '/\bUPDATE\b/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $json)) {
                return true;
            }
        }

        return false;
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return strip_tags($value);
            }
            return $value;
        }, $data);
    }

    protected function isDeletionAllowed(string $modelType, array $data): bool
    {
        return !($this->config['prevent_deletion'][$modelType] ?? false);
    }

    protected function createAuditTrail(string $action, string $modelType, array $data, array $context): void
    {
        Log::info("Data {$action}", [
            'model_type' => $modelType,
            'data_id' => $data['id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'timestamp' => time()
        ]);
    }
}
