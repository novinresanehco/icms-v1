<?php

namespace App\Core\CMS;

class ContentManager
{
    private Repository $repository;
    private Validator $validator;
    private CacheService $cache;

    public function validateInput(array $data): array
    {
        // Critical data validation
        if (!$this->validator->validateCritical($data)) {
            throw new ValidationException('Critical validation failed');
        }

        // Security sanitization
        return $this->validator->sanitize($data);
    }

    public function create(array $data): int
    {
        DB::beginTransaction();
        
        try {
            $id = $this->repository->create($data);
            $this->cache->invalidate('content');
            
            DB::commit();
            return $id;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        DB::beginTransaction();
        
        try {
            $this->repository->update($id, $data);
            $this->cache->invalidate("content.$id");
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        DB::beginTransaction();
        
        try {
            $this->repository->delete($id);
            $this->cache->invalidate("content.$id");
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function read(int $id): array
    {
        // Try cache first
        if ($data = $this->cache->get("content.$id")) {
            return $data;
        }

        // Read from repository
        $data = $this->repository->find($id);
        
        // Cache the result
        $this->cache->set("content.$id", $data);
        
        return $data;
    }
}
