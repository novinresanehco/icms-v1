namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface
{
    private AuthenticationService $auth;
    private AccessControl $access;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation, $context);
            $result = $this->executeWithProtection($operation);
            $this->verifyResult($result);
            
            DB::commit();
            $this->logger->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw $e;
        }
    }

    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void
    {
        if (!$this->auth->validateSession($context)) {
            throw new AuthenticationException();
        }

        if (!$this->access->checkPermission($context, $operation->getRequiredPermissions())) {
            throw new AccessDeniedException();
        }

        $this->validator->validateOperation($operation, $context);
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor();
        return $monitor->execute(fn() => $operation->execute());
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }
}

class AccessControl
{
    private RoleManager $roles;
    private PermissionRegistry $permissions;
    private AuditLogger $logger;

    public function checkPermission(SecurityContext $context, array $requiredPermissions): bool
    {
        $user = $context->getUser();
        $userRoles = $this->roles->getUserRoles($user);
        
        foreach ($requiredPermissions as $permission) {
            if (!$this->hasPermission($userRoles, $permission)) {
                $this->logger->logAccessDenied($user, $permission);
                return false;
            }
        }
        
        return true;
    }

    private function hasPermission(array $roles, string $permission): bool
    {
        foreach ($roles as $role) {
            if ($this->permissions->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }
}

class AuthenticationService
{
    private TokenManager $tokens;
    private SessionManager $sessions;
    private AuditLogger $logger;

    public function validateSession(SecurityContext $context): bool
    {
        $token = $context->getToken();
        
        if (!$this->tokens->isValid($token)) {
            $this->logger->logInvalidToken($token);
            return false;
        }

        if ($this->sessions->isExpired($token)) {
            $this->logger->logSessionExpired($token);
            return false;
        }

        return true;
    }

    public function refreshSession(string $token): string
    {
        if (!$this->tokens->isValid($token)) {
            throw new AuthenticationException('Invalid token');
        }

        return $this->tokens->refresh($token);
    }
}

class EncryptionService
{
    private string $key;
    private string $cipher;

    public function encrypt(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, $this->cipher, $this->key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string
    {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, $this->cipher, $this->key, 0, $iv);
    }

    public function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }
}
