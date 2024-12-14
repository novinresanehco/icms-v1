<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityContext;
use App\Core\Services\{ValidationService, CompressionService, AuditService};
use App\Core\Exceptions\{CacheException, SecurityException, ValidationException};

class CacheManager implements CacheManagerInterface
{
    private ValidationService $validator;
    private CompressionService $compression;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        CompressionService $compression,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->compression = $compression;
        $this->audit = $audit;
        $this->config = config('cache');
    }

    public function store(string $key, mixed $value, ?int $ttl = null, SecurityContext $context): bool
    {
        try {
            // Validate key and value
            $this->validateCacheData($key, $value);

            // Process value for caching
            $processedValue = $this->processForCache($value);

            // Security check
            $this->verifySecurity($key, $processedValue, $context);

            // Store with backup
            return DB::transaction(function() use ($key, $processedValue, $ttl, $context) {
                // Create backup if needed
                $this->createBackup($key);

                // Store with encryption if needed
                $success = $this->secureCacheStore($key, $processedValue, $ttl);

                // Update cache metadata
                $this->updateMetadata($key, $context);

                // Log operation
                $this->audit->logCacheOperation('store', $key, $context);

                return $success;
            });

        } catch (\Exception $e) {
            $this->handleStorageFailure($e, $key, $context);
            throw new CacheException('Cache storage failed: ' . $e->getMessage());
        }
    }

    public function retrieve(string $key, SecurityContext $context): mixed
    {
        try {
            // Verify access
            $this->verifyAccess($key, $context);

            // Get from cache
            $value = $this->secureCacheGet($key);

            if ($value === null) {
                $this->audit->logCacheMiss($key, $context);
                return null;
            }

            // Verify integrity
            $this->verifyIntegrity($key, $value);

            // Process retrieved value
            $processedValue = $this->processRetrieved($value);

            // Log successful retrieval
            $this->audit->logCacheOperation('retrieve', $key, $context);

            return $processedValue;

        } catch (\Exception $e) {
            $this->handleRetrievalFailure($e, $key, $context);
            throw new CacheException('Cache retrieval failed: ' . $e->getMessage());
        }
    }

    public function invalidate(string $key, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($key, $context) {
            try {
                // Verify invalidation permission
                $this->verifyInvalidationPermission($key, $context);

                // Create backup before invalidation
                $this->createBackup($key);

                // Perform invalidation
                $success = $this->secureCacheInvalidate($key);

                // Clean up related caches
                $this->cleanupRelatedCaches($key);

                // Update metadata
                $this->removeMetadata($key);

                // Log invalidation
                $this->audit->logCacheOperation('invalidate', $key, $context);

                return $success;

            } catch (\Exception $e) {
                $this->handleInvalidationFailure($e, $key, $context);
                throw new CacheException('Cache invalidation failed: ' . $e->getMessage());
            }
        });
    }

    private function validateCacheData(string $key, mixed $value): void
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new ValidationException('Invalid cache key format');
        }

        if (!$this->validator->validateCacheValue($value)) {
            throw new ValidationException('Invalid cache value format');
        }
    }

    private function processForCache(mixed $value): mixed
    {
        // Serialize if needed
        if (is_object($value) || is_array($value)) {
            $value = serialize($value);
        }

        // Compress if large
        if ($this->shouldCompress($value)) {
            $value = $this->compression->compress($value);
        }

        return $value;
    }

    private function verifySecurity(string $key, mixed $value, SecurityContext $context): void
    {
        if (!$this->hasStorePermission($key, $context)) {
            throw new SecurityException('Cache store permission denied');
        }

        if ($this->isSecuritySensitive($value) && !$context->hasHighSecurityClearance()) {
            throw new SecurityException('Insufficient security clearance for sensitive data');
        }
    }

    private function secureCacheStore(string $key, mixed $value, ?int $ttl): bool
    {
        $options = $this->buildStoreOptions($key);
        return Cache::put($key, $value, $ttl ?? $this->config['default_ttl'], $options);
    }

    private function secureCacheGet(string $key): mixed
    {
        $options = $this->buildRetrieveOptions($key);
        return Cache::get($key, null, $options);
    }

    private function secureCacheInvalidate(string $key): bool
    {
        return Cache::forget($key);
    }

    private function verifyAccess(string $key, SecurityContext $context): void
    {
        if (!$this->hasRetrievePermission($key, $context)) {
            throw new SecurityException('Cache retrieve permission denied');
        }
    }

    private function verifyIntegrity(string $key, mixed $value): void
    {
        if (!$this->validator->validateIntegrity($key, $value)) {
            throw new SecurityException('Cache integrity check failed');
        }
    }

    private function verifyInvalidationPermission(string $key, SecurityContext $context): void
    {
        if (!$this->hasInvalidationPermission($key, $context)) {
            throw new SecurityException('Cache invalidation permission denied');
        }
    }

    private function createBackup(string $key): void
    {
        if ($this->config['backup_enabled'] && $this->requiresBackup($key)) {
            $value = Cache::get($key);
            if ($value !== null) {
                $this->storeBackup($key, $value);
            }
        }
    }

    private function shouldCompress(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return strlen($value) > $this->config['compression_threshold'];
    }

    private function cleanupRelatedCaches(string $key): void
    {
        foreach ($this->getRelatedCacheKeys($key) as $relatedKey) {
            Cache::forget($relatedKey);
        }
    }

    private function handleStorageFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logCacheFailure('store', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleRetrievalFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logCacheFailure('retrieve', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleInvalidationFailure(\Exception $e, string $key, SecurityContext $context): void
    {
        $this->audit->logCacheFailure('invalidate', $key, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
