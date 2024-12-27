<?php

namespace App\Core\Security;

use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function validateAccess(SecurityContext $context): ValidationResult 
    {
        DB::beginTransaction();
        
        try {
            $this->validateRequest($context);
            $this->checkPermissions($context);
            $this->verifyIntegrity($context);
            
            DB::commit();
            $this->logger->logAccess($context);
            
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    public function encryptData(array $data): string
    {
        return $this->cache->remember('encrypted:' . md5(serialize($data)), function() use ($data) {
            return openssl_encrypt(
                serialize($data),
                'AES-256-GCM',
                $this->getEncryptionKey(),
                0,
                $iv = random_bytes(16),
                $tag
            );
        });
    }

    public function decryptData(string $encrypted): array
    {
        return unserialize(openssl_decrypt(
            $encrypted,
            'AES-256-GCM', 
            $this->getEncryptionKey(),
            0,
            $iv,
            $tag
        ));
    }

    private function validateRequest(SecurityContext $context): void
    {
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request');
        }
    }

    private function checkPermissions(SecurityContext $context): void
    {
        if (!$this->hasPermission($context->getUser(), $context->getResource())) {
            throw new AccessDeniedException();
        }
    }

    private function hasPermission(User $user, string $resource): bool 
    {
        return $this->cache->remember("perm:{$user->id}:{$resource}", function() use ($user, $resource) {
            return $user->hasPermission($resource);
        });
    }
}
