<?php

namespace App\Core\Lock;

use App\Core\Interfaces\LockManagerInterface;
use App\Core\Exceptions\{LockException, SecurityException};
use Illuminate\Support\Facades\{Cache, Redis, Log};

class LockManager implements LockManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $activeLocks = [];
    private array $lockPriorities;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->lockPriorities = $config['lock_priorities'];
    }

    public function acquireLock(string $resource, int $priority): string
    {
        $lockId = $this->generateLockId($resource);
        
        try {
            // Validate lock request
            $this->validateLockRequest($resource, $priority);
            
            // Attempt to acquire lock
            if (!$this->tryAcquireLock($resource, $lockId, $priority)) {
                throw new LockException("Failed to acquire lock for: $resource");
            }
            
            // Register lock
            $this->registerLock($lockId, $resource, $priority);
            
            // Monitor lock health
            $this->monitorLock($lockId);
            
            return $lockId;
            
        } catch (\Exception $e) {
            $this->handleLockFailure($e, $resource);
            throw $e;
        }
    }

    public function releaseLock(string $lockId): void
    {
        try {
            // Validate release request
            $this->validateLockRelease($lockId);
            
            // Release the lock
            $this->performLockRelease($lockId);
            
            // Cleanup lock data
            $this->cleanupLock($lockId);
            
        } catch (\Exception $e) {
            $this->handleReleaseFailure($e, $lockId);
            throw new LockException("Failed to release lock: $lockId", $e);
        }
    }

    protected function validateLockRequest(string $resource, int $priority): void
    {
        if (!$this->validator->validateResource($resource)) {
            throw new LockException("Invalid resource: $resource");
        }

        if (!$this->security->validateLockRequest($resource, $priority)) {
            throw new SecurityException("Unauthorized lock request");
        }
    }

    protected function tryAcquireLock(string $resource, string $lockId, int $priority): bool
    {
        return Redis::set(
            $this->getLockKey($resource),
            $lockId,
            'NX',
            'PX',
            $this->getLockTTL($priority)
        );
    }

    protected function registerLock(string $lockId, string $resource, int $priority): void
    {
        $this->activeLocks[$lockId] = [
            'resource' => $resource,
            'priority' => $priority,
            'acquired_at' => microtime(true)
        ];

        Cache::put(
            $this->getLockRegistryKey($lockId),
            $this->activeLocks[$lockId],
            now()->addMinutes(60)
        );
    }

    protected function monitorLock(string $lockId): void
    {
        $lock = $this->activeLocks[$lockId];
        
        if ($this->security->detectLockAnomaly($lock)) {
            throw new SecurityException("Lock anomaly detected: $lockId");
        }
    }

    protected function performLockRelease(string $lockId): void
    {
        if (!isset($this->activeLocks[$lockId])) {
            throw new LockException("Invalid lock ID: $lockId");
        }

        $resource = $this->activeLocks[$lockId]['resource'];
        Redis::del($this->getLockKey($resource));
    }

    protected function cleanupLock(string $lockId): void
    {
        unset($this->activeLocks[$lockId]);
        Cache::forget($this->getLockRegistryKey($lockId));
    }

    protected function getLockKey(string $resource): string
    {
        return "lock:$resource";
    }

    protected function getLockRegistryKey(string $lockId): string
    {
        return "lock_registry:$lockId";
    }

    protected function getLockTTL(int $priority): int
    {
        return $this->lockPriorities[$priority]['ttl'] ?? 30000;
    }

    protected function generateLockId(string $resource): string
    {
        return uniqid("lock:$resource:", true);
    }

    protected function handleLockFailure(\Exception $e, string $resource): void
    {
        Log::error("Lock acquisition failed for $resource", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleReleaseFailure(\Exception $e, string $lockId): void
    {
        Log::error("Lock release failed for $lockId", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
