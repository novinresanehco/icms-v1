<?php

namespace App\Core;

use App\Core\Security\SecurityManager;
use App\Core\Content\ContentManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class CoreCMS
{
    private SecurityManager $security;
    private ContentManager $content;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ContentManager $content, 
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Execute critical CMS operation with comprehensive protection
     */
    public function executeOperation(string $operation, array $data): mixed
    {
        // Pre-execution security validation
        $this->security->validateOperation($operation, $data);
        
        DB::beginTransaction();
        
        try {
            // Execute with caching and monitoring
            $result = match($operation) {
                'content.create' => $this->content->create($data),
                'content.update' => $this->content->update($data['id'], $data),
                'content.delete' => $this->content->delete($data['id']),
                default => throw new \InvalidArgumentException('Invalid operation')
            };

            // Cache result if applicable
            if ($this->shouldCache($operation)) {
                $this->cache->put(
                    $this->getCacheKey($operation, $data),
                    $result,
                    $this->config['cache_ttl']
                );
            }
            
            DB::commit();
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation, $data);
            throw $e;
        }
    }

    private function shouldCache(string $operation): bool
    {
        return in_array($operation, $this->config['cacheable_operations']);
    }

    private function getCacheKey(string $operation, array $data): string
    {
        return sprintf(
            '%s:%s:%s',
            $operation,
            $data['id'] ?? 'new',
            md5(serialize($data))
        );
    }

    private function handleFailure(\Throwable $e, string $operation, array $data): void
    {
        // Log failure details
        \Log::error('Operation failed', [
            'operation' => $operation,
            'data' => $data,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Clear related cache
        if ($this->shouldCache($operation)) {
            $this->cache->forget($this->getCacheKey($operation, $data));
        }

        // Additional failure handling like notifications can be added here
    }
}
