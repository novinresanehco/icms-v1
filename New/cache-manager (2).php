<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManager;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private int $ttl;
    private MetricsCollector $metrics;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateKey($key);
        
        if ($cached = $this->get($cacheKey)) {
            $this->metrics->recordCacheHit($key);
            return $cached;
        }

        $value = $callback();
        $this->set($cacheKey, $value, $ttl ?? $this->ttl);
        $this->metrics->recordCacheMiss($key);
        
        return $value;
    }

    public function get(string $key): mixed
    {
        $value = $this->store->get($key);
        return $value ? $this->security->decryptData($value) : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $encrypted = $this->security->encryptData($value);
        $this->store->put($key, $encrypted, $ttl ?? $this->ttl);
    }

    public function invalidate(string $key): void
    {
        $this->store->forget($this->generateKey($key));
    }

    private function generateKey(string $key): string
    {
        return hash('sha256', $key);
    }
}

class CacheStore
{
    private \PDO $db;
    
    public function get(string $key): mixed
    {
        $sql = "SELECT content FROM template_cache 
                WHERE template_hash = :key AND expires_at > NOW()";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['key' => $key]);
        
        return $stmt->fetchColumn();
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $sql = "INSERT INTO template_cache 
                (template_hash, content, expires_at) VALUES 
                (:key, :value, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
                ON DUPLICATE KEY UPDATE 
                content = VALUES(content), 
                expires_at = VALUES(expires_at)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'key' => $key,
            'value' => $value,
            'ttl' => $ttl
        ]);
    }

    public function forget(string $key): void
    {
        $sql = "DELETE FROM template_cache WHERE template_hash = :key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['key' => $key]);
    }
}

class MetricsCollector
{
    private \PDO $db;

    public function recordCacheHit(string $key): void
    {
        $this->record('cache_hit', $key);
    }

    public function recordCacheMiss(string $key): void
    {
        $this->record('cache_miss', $key);
    }

    private function record(string $type, string $key): void
    {
        $sql = "INSERT INTO template_metrics (name, value, tags, timestamp, environment)
                VALUES (:name, :value, :tags, NOW(), :environment)";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'name' => $type,
            'value' => 1,
            'tags' => json_encode(['key' => $key]),
            'environment' => APP_ENV
        ]);
    }
}
