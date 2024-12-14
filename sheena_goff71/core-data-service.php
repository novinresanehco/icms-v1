<?php

namespace App\Core\Data;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityContext;
use App\Core\Services\{ValidationService, CacheService, AuditService};
use App\Core\Exceptions\{DataException, ValidationException};

class DataService implements DataServiceInterface
{
    private ValidationService $validator;
    private CacheService $cache;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        CacheService $cache,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->config = config('data');
    }

    public function store(string $key, array $data, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($key, $data, $context) {
            try {
                // Validate data structure
                $validatedData = $this->validateData($data);
                
                // Process and optimize data
                $processedData = $this->processData($validatedData);
                
                // Store with backup
                $this->createBackup($key);
                $this->storeData($key, $processedData);
                
                // Update cache
                $this->updateCache($key, $processedData);
                
                // Log operation
                $this->audit->logDataOperation('store', $key, $context);
                
                return true;

            } catch (\Exception $e) {
                $this->handleStorageFailure($e, $key, $context);
                throw new DataException('Storage operation failed', 0, $e);
            }
        });
    }

    public function retrieve(string $key, SecurityContext $context): array
    {
        try {
            // Check cache first
            if ($cachedData = $this->getFromCache($key)) {
                $this->audit->logCacheHit($key, $context);
                return $cachedData;
            }

            // Get from database
            $data = $this->getFromDatabase($key);
            
            // Validate retrieved data
            if (!$this->validateData($data)) {
                throw new ValidationException('Retrieved data validation failed');
            }

            // Update cache
            $this->updateCache($key, $data);
            
            // Log retrieval
            $this->audit->logDataOperation('retrieve', $key, $context);

            return $data;

        } catch (\Exception $e) {
            $this->handleRetrievalFailure($e, $key, $context);
            throw new DataException('Retrieval operation failed', 0, $e);
        }
    }

    public function update(string $key, array $data, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($key, $data, $context) {
            try {
                // Validate update data
                $validatedData = $this->validateData($data);
                
                // Create backup before update
                $this->createBackup($key);
                
                // Perform update
                $this->updateData($key, $validatedData);
                
                // Update cache
                $this->invalidateCache($key);
                $this->updateCache($key, $validatedData);
                
                // Log update
                $this->audit->logDataOperation('update', $key, $context);
                
                return true;

            } catch (\Exception $e) {
                $this->handleUpdateFailure($e, $key, $context);
                throw new DataException('Update operation failed', 0, $e);
            }
        });
    }

    public function delete(string $key, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($key, $context) {
            try {
                // Create backup before deletion
                $this->createBackup($key);
                
                // Perform deletion
                $this->deleteData($key);
                
                // Clear cache
                $this->invalidateCache($key);
                
                // Log deletion
                $this->audit->logDataOperation('delete', $key, $context);
                
                return true;

            } catch (\Exception $e) {
                $this->handleDeletionFailure($e, $key, $context);
                throw new DataException('Deletion operation failed', 0, $e);
            }
        });
    }

    private function validateData(array $data): array
    {
        $rules = $this->config['validation_rules'];
        return $this->validator->validate($data, $rules);
    }

    private function processData(array $data): array
    {
        // Optimize data structure
        $data = $this->optimizeDataStructure($data);
        
        // Apply compression if needed
        if ($this->shouldCompress($data)) {
            $data = $this->compressData($data);
        }
        
        return $data;
    }

    private function optimizeDataStructure(array $data): array
    {
        // Remove unused fields
        $data = array_intersect_key($data, array_flip($this->config['allowed_fields']));
        
        // Normalize data formats
        foreach ($data as $key => $value) {
            $data[$key] = $this->normalizeValue($value);
        }
        
        return $data;
    }

    private function createBackup(string $key): void
    {
        $currentData = $this->getFromDatabase($key);
        
        if ($currentData) {
            DB::table($this->config['backup_table'])->insert([
                'key' => $key,
                'data' => json_encode($currentData),
                'created_at' => now()
            ]);
        }
    }

    private function getFromCache(string $key): ?array
    {
        return $this->cache->remember(
            $this->getCacheKey($key),
            $this->config['cache_ttl'],
            fn() => $this->getFromDatabase($key)
        );
    }

    private function getFromDatabase(string $key): ?array
    {
        $result = DB::table($this->config['data_table'])
            ->where('key', $key)
            ->first();
            
        return $result ? json_decode($result->data, true) : null;
    }

    private function storeData(string $key, array $data): void
    {
        DB::table($this->config['data_table'])->insert([
            'key' => $key,
            'data' => json_encode($data),
            'created_at' => now()
        ]);
    }

    private function updateData(string $key, array $data): void
    {
        DB::table($this->config['data_table'])
            ->where('key', $key)
            ->update([
                'data' => json_encode($data),
                'updated_at' => now()
            ]);
    }

    private function deleteData(string $key): void
    {
        DB::table($this->config['data_table'])
            ->where('key', $key)
            ->delete();
    }

    private function updateCache(string $key, array $data): void
    {
        $this->cache->put(
            $this->getCacheKey($key),
            $data,
            $this->config['cache_ttl']
        );
    }

    private function invalidateCache(string $key): void
    {
        $this->cache->forget($this->getCacheKey($key));
    }

    private function getCacheKey(string $key): string
    {
        return "data:{$key}";
    }

    private function shouldCompress(array $data): bool
    {
        return strlen(json_encode($data)) > $this->config['compression_threshold'];
    }

    private function compressData(array $data): array
    {
        $compressed = gzcompress(json_encode($data));
        return ['compressed' => true, 'data' => base64_encode($compressed)];
    }

    private function handleStorageFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logFailure('storage', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleRetrievalFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logFailure('retrieval', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleUpdateFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logFailure('update', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleDeletionFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logFailure('deletion', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
