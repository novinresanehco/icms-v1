<?php

namespace App\Core\Data;

use App\Core\Security\SecurityCore;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Exceptions\DataException;

class DataManager implements DataManagerInterface
{
    private SecurityCore $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityCore $security,
        CacheManager $cache,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function storeData(array $data, SecurityContext $context): DataResult
    {
        return $this->security->validateSecureOperation(
            function() use ($data, $context) {
                // Validate input
                $validatedData = $this->validator->validateData($data);

                // Store with transaction protection
                return DB::transaction(function() use ($validatedData, $context) {
                    // Generate metadata
                    $metadata = $this->generateMetadata($validatedData, $context);
                    
                    // Store data with metadata
                    $result = $this->repository->store($validatedData, $metadata);
                    
                    // Set permissions
                    $this->setDataPermissions($result->id, $context);
                    
                    // Invalidate relevant caches
                    $this->invalidateCaches($result->id);
                    
                    return new DataResult($result);
                });
            },
            $context
        );
    }

    public function retrieveData(int $id, SecurityContext $context): DataResult
    {
        if (!$this->security->verifyAccess("data:$id", 'read', $context)) {
            throw new DataException('Access denied');
        }

        // Check cache first
        $cacheKey = "data:$id:" . $context->userId;
        if ($cached = $this->cache->get($cacheKey)) {
            return new DataResult($cached);
        }

        $result = DB::transaction(function() use ($id, $context) {
            $data = $this->repository->find($id);
            if (!$data) {
                throw new DataException('Data not found');
            }

            // Verify data integrity
            $this->verifyDataIntegrity($data);

            // Load permissions
            $this->loadDataPermissions($data, $context);

            return $data;
        });

        // Cache the result
        $this->cache->set($cacheKey, $result, $this->config['cache_ttl']);

        return new DataResult($result);
    }

    public function updateData(int $id, array $data, SecurityContext $context): DataResult
    {
        if (!$this->security->verifyAccess("data:$id", 'update', $context)) {
            throw new DataException('Update access denied');
        }

        return $this->security->validateSecureOperation(
            function() use ($id, $data, $context) {
                $validatedData = $this->validator->validateData($data);
                
                return DB::transaction(function() use ($id, $validatedData, $context) {
                    // Update data
                    $result = $this->repository->update($id, $validatedData);
                    
                    // Update metadata
                    $this->updateMetadata($result);
                    
                    // Invalidate caches
                    $this->invalidateCaches($id);
                    
                    return new DataResult($result);
                });
            },
            $context
        );
    }

    public function deleteData(int $id, SecurityContext $context): bool
    {
        if (!$this->security->verifyAccess("data:$id", 'delete', $context)) {
            throw new DataException('Delete access denied');
        }

        return $this->security->validateSecureOperation(
            function() use ($id) {
                return DB::transaction(function() use ($id) {
                    // Delete data
                    $this->repository->delete($id);
                    
                    // Clear permissions
                    $this->clearPermissions($id);
                    
                    // Clear caches
                    $this->invalidateCaches($id);
                    
                    return true;
                });
            },
            $context
        );
    }

    private function generateMetadata(array $data, SecurityContext $context): array
    {
        return [
            'created_at' => now(),
            'created_by' => $context->userId,
            'version' => 1,
            'checksum' => $this->generateChecksum($data),
            'encryption_key_id' => $this->security->generateKeyId()
        ];
    }

    private function setDataPermissions(int $dataId, SecurityContext $context): void
    {
        $permissions = [
            'owner' => $context->userId,
            'roles' => $context->roles,
            'access_level' => $this->config['default_access_level']
        ];

        $this->permissionRepository->setPermissions($dataId, $permissions);
    }

    private function verifyDataIntegrity($data): void
    {
        if (!$this->validator->verifyIntegrity($data)) {
            throw new DataException('Data integrity check failed');
        }
    }

    private function invalidateCaches(int $dataId): void
    {
        $keys = [
            "data:$dataId",
            "data:$dataId:meta",
            "data:list"
        ];

        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
    }

    private function updateMetadata($data): void
    {
        $metadata = [
            'updated_at' => now(),
            'version' => $data->version + 1,
            'checksum' => $this->generateChecksum($data)
        ];

        $this->repository->updateMetadata($data->id, $metadata);
    }

    private function clearPermissions(int $dataId): void
    {
        $this->permissionRepository->clearPermissions($dataId);
    }

    private function generateChecksum(array $data): string
    {
        return hash('sha256', serialize($data));
    }
}
