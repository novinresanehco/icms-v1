<?php

namespace App\Core\Data;

class CriticalDataManager implements DataManagerInterface
{
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected AuditLogger $logger;

    public function store(string $key, array $data): bool
    {
        DB::beginTransaction();
        try {
            // Critical data validation
            if (!$this->validator->validateCritical($data)) {
                throw new ValidationException('Critical validation failed');
            }

            // Store with basic versioning
            $result = DB::table('critical_data')->insert([
                'key' => $key,
                'data' => json_encode($data),
                'version' => $this->getNextVersion($key),
                'created_at' => now()
            ]);

            // Clear cache
            $this->cache->forget("data:$key");

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->criticalError('data.store', $e);
            throw $e;
        }
    }

    public function retrieve(string $key): array
    {
        return $this->cache->remember("data:$key", 300, function() use ($key) {
            $record = DB::table('critical_data')
                       ->where('key', $key)
                       ->orderBy('version', 'desc')
                       ->first();

            return $record ? json_decode($record->data, true) : [];
        });
    }

    protected function getNextVersion(string $key): int
    {
        return DB::table('critical_data')
                 ->where('key', $key)
                 ->max('version') + 1;
    }
}

class ValidationService
{
    public function validateCritical(array $data): bool
    {
        // Only essential validations for 3-day delivery
        return !empty($data) && 
               isset($data['type']) && 
               isset($data['content']);
    }
}

class CacheManager
{
    public function remember(string $key, int $ttl, callable $callback)
    {
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $value = $callback();
        Cache::put($key, $value, $ttl);
        return $value;
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }
}

class AuditLogger
{
    public function criticalError(string $operation, \Exception $e): void
    {
        Log::error("Critical data operation failed: $operation", [
            'error' => $e->getMessage(),
            'user' => request()->user()?->id
        ]);
    }
}
