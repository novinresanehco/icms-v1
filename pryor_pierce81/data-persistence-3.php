<?php

namespace App\Core\Data;

class SecureDataManager {
    private Database $db;
    private Cache $cache;
    private CriticalDataValidator $validator;
    private SecurityService $security;
    
    public function store(array $data, string $context): int {
        // Validate before storage
        $this->validator->validateCritical($data, $context);

        // Encrypt sensitive data
        $data = $this->security->encrypt($data);

        // Store with transaction
        DB::beginTransaction();
        try {
            $id = $this->db->insert($data);
            $this->cache->invalidateContext($context);
            DB::commit();
            return $id;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new StorageException('Storage failed', 0, $e);
        }
    }

    public function retrieve(int $id, string $context): array {
        // Check cache first
        if($data = $this->cache->get($this->getCacheKey($id, $context))) {
            return $data;
        }

        // Get from database
        $data = $this->db->find($id);
        if(!$data) {
            throw new NotFoundException();
        }

        // Decrypt and validate
        $data = $this->security->decrypt($data);
        $this->validator->validateCritical($data, $context);

        // Cache and return
        $this->cache->set($this->getCacheKey($id, $context), $data);
        return $data;
    }

    private function getCacheKey(int $id, string $context): string {
        return "data:{$context}:{$id}";
    }
}
