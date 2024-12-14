<?php

namespace App\Core\CMS;

class ContentRepository
{
    private $security;
    private $cache;
    private $db;

    public function findWithCache(int $id): ?array
    {
        // Try cache first
        if ($data = $this->cache->get("content:$id")) {
            return $data;
        }

        // Get from database
        $data = $this->db->table('content')
                        ->where('id', $id)
                        ->whereNull('deleted_at')
                        ->first();

        if ($data) {
            // Cache successful result
            $this->cache->set("content:$id", $data, 3600);
        }

        return $data;
    }

    public function create(array $data): int
    {
        // Encrypt sensitive data
        $encrypted = $this->security->encryptContent($data);

        // Store in database
        $id = $this->db->table('content')->insertGetId($encrypted);

        // Clear relevant caches
        $this->cache->invalidatePattern('content:list:*');

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $encrypted = $this->security->encryptContent($data);
        
        $this->db->table('content')
                 ->where('id', $id)
                 ->update($encrypted);

        // Invalidate caches
        $this->cache->delete("content:$id");
        $this->cache->invalidatePattern('content:list:*');
    }
}
