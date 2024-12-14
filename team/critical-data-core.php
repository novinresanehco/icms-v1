<?php

namespace App\Core\Data;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Exceptions\{DataException, ValidationException};

class DataManager implements DataManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const CACHE_PREFIX = 'data_layer';
    private const CACHE_TTL = 3600;
    private const MAX_BATCH_SIZE = 1000;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function store(string $type, array $data): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeStore($type, $data),
            ['action' => 'data.store', 'type' => $type]
        );
    }

    protected function executeStore(string $type, array $data): array
    {
        $validationRules = $this->getValidationRules($type);
        $validated = $this->validator->validate($data, $validationRules);

        DB::beginTransaction();
        try {
            $storedData = DB::table($type)->insertGetId(
                $this->prepareDataForStorage($validated)
            );

            $result = $this->retrieveStoredData($type, $storedData);
            
            $this->invalidateCache($type, $storedData);
            
            DB::commit();

            $this->audit->log('data.stored', [
                'type' => $type,
                'id' => $storedData
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new DataException("Failed to store {$type}: " . $e->getMessage());
        }
    }

    public function retrieve(string $type, int $id): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRetrieve($type, $id),
            ['action' => 'data.retrieve', 'type' => $type, 'id' => $id]
        );
    }

    protected function executeRetrieve(string $type, int $id): array
    {
        $cacheKey = $this->generateCacheKey($type, $id);

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($type, $id) {
            $data = DB::table($type)->find($id);

            if (!$data) {
                throw new DataException("Data not found: {$type} #{$id}");
            }

            $this->audit->log('data.retrieved', [
                'type' => $type,
                'id' => $id
            ]);

            return $this->prepareDataForReturn((array)$data);
        });
    }

    public function batchRetrieve(string $type, array $ids): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeBatchRetrieve($type, $ids),
            ['action' => 'data.batch_retrieve', 'type' => $type]
        );
    }

    protected function executeBatchRetrieve(string $type, array $ids): array
    {
        if (count($ids) > self::MAX_BATCH_SIZE) {
            throw new ValidationException("Batch size exceeds maximum of " . self::MAX_BATCH_SIZE);
        }

        $result = [];
        $uncached = [];
        
        // Check cache first
        foreach ($ids as $id) {
            $cacheKey = $this->generateCacheKey($type, $id);
            $cached = Cache::get($cacheKey);
            
            if ($cached) {
                $result[$id] = $cached;
            } else {
                $uncached[] = $id;
            }
        }

        // Fetch uncached data
        if (!empty($uncached)) {
            $data = DB::table($type)
                ->whereIn('id', $uncached)
                ->get();

            foreach ($data as $item) {
                $prepared = $this->prepareDataForReturn((array)$item);
                $result[$item->id] = $prepared;
                
                // Cache individual items
                Cache::put(
                    $this->generateCacheKey($type, $item->id),
                    $prepared,
                    self::CACHE_TTL
                );
            }
        }

        $this->audit->log('data.batch_retrieved', [
            'type' => $type,
            'count' => count($ids),
            'cache_hits' => count($ids) - count($uncached)
        ]);

        return $result;
    }

    public function update(string $type, int $id, array $data): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($type, $id, $data),
            ['action' => 'data.update', 'type' => $type, 'id' => $id]
        );
    }

    protected function executeUpdate(string $type, int $id, array $data): array
    {
        $validationRules = $this->getValidationRules($type);
        $validated = $this->validator->validate($data, $validationRules);

        DB::beginTransaction();
        try {
            $updated = DB::table($type)
                ->where('id', $id)
                ->update($this->prepareDataForStorage($validated));

            if (!$updated) {
                throw new DataException("Failed to update {$type} #{$id}");
            }

            $result = $this->retrieveStoredData($type, $id);
            
            $this->invalidateCache($type, $id);
            
            DB::commit();

            $this->audit->log('data.updated', [
                'type' => $type,
                'id' => $id
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new DataException("Failed to update {$type}: " . $e->getMessage());
        }
    }

    protected function generateCacheKey(string $type, int $id): string
    {
        return implode(':', [self::CACHE_PREFIX, $type, $id]);
    }

    protected function invalidateCache(string $type, int $id): void
    {
        Cache::forget($this->generateCacheKey($type, $id));
        Cache::tags($type)->flush();
    }

    protected function getValidationRules(string $type): array
    {
        return $this->config['types'][$type]['validation'] ?? [];
    }

    protected function prepareDataForStorage(array $data): array
    {
        unset($data['id']);
        $data['updated_at'] = now();
        return $data;
    }

    protected function prepareDataForReturn(array $data): array
    {
        return array_map(function ($value) {
            return is_resource($value) ? stream_get_contents($value) : $value;
        }, $data);
    }

    protected function retrieveStoredData(string $type, int $id): array
    {
        return $this->prepareDataForReturn(
            (array)DB::table($type)->find($id)
        );
    }
}
