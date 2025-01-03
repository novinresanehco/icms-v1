<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface
{
    private AuthManager $auth;
    private AccessControl $access;
    private AuditLogger $audit;
    private CacheManager $cache;
    private EncryptionService $encryption;
    private array $config;

    public function __construct(
        AuthManager $auth,
        AccessControl $access, 
        AuditLogger $audit,
        CacheManager $cache,
        EncryptionService $encryption,
        array $config
    ) {
        $this->auth = $auth;
        $this->access = $access;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function validateContext(SecurityContext $context): bool
    {
        try {
            $this->validateSession($context);
            $this->validatePermissions($context);
            $this->validateResourceAccess($context);
            $this->audit->logAccess($context);
            return true;
        } catch (SecurityException $e) {
            $this->audit->logFailure($e, $context);
            throw $e;
        }
    }

    public function validateOperation(string $operation, SecurityContext $context): void
    {
        if (!$this->access->hasPermission($context->getUser(), $operation)) {
            throw new SecurityException('Operation not permitted');
        }
    }

    public function validateAccess(Model $resource, SecurityContext $context): void
    {
        if (!$this->access->canAccess($context->getUser(), $resource)) {
            throw new SecurityException('Access denied');
        }
    }

    public function encrypt($data): string
    {
        return $this->encryption->encrypt(serialize($data));
    }

    public function decrypt(string $encrypted): mixed
    {
        return unserialize($this->encryption->decrypt($encrypted));
    }

    public function logAudit(string $action, array $data, SecurityContext $context): void
    {
        $this->audit->log($action, $data, $context);
    }

    protected function validateSession(SecurityContext $context): void
    {
        if (!$this->auth->validateSession($context->getSession())) {
            throw new SecurityException('Invalid session');
        }
    }

    protected function validatePermissions(SecurityContext $context): void
    {
        if (!$this->access->validatePermissions($context->getUser())) {
            throw new SecurityException('Invalid permissions');
        }
    }

    protected function validateResourceAccess(SecurityContext $context): void
    {
        if (!$this->access->validateResourceAccess($context)) {
            throw new SecurityException('Invalid resource access');
        }
    }
}
