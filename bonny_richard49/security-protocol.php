namespace App\Core\Security;

class ValidationService
{
    private array $rules;
    private array $messages;

    public function validate(array $data, array $rules): void 
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException(
                    $this->messages[$field] ?? "Validation failed for $field"
                );
            }
        }
    }

    private function validateField($value, string $rule): bool 
    {
        return match($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL),
            'url' => filter_var($value, FILTER_VALIDATE_URL),
            default => true
        };
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

    public function verifyIntegrity(string $data): bool 
    {
        try {
            return $this->decrypt($data) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
}

class AuthenticationService
{
    private TokenManager $tokens;
    private SessionManager $sessions;
    private SecurityConfig $config;

    public function validateSession(SecurityContext $context): bool 
    {
        $token = $context->getToken();
        
        if (!$this->tokens->isValid($token)) {
            return false;
        }

        if ($this->sessions->isExpired($token)) {
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

class AccessControl
{
    private RoleManager $roles;
    private PermissionRegistry $permissions;
    private AuditLogger $logger;

    public function hasPermissions(User $user, array $permissions): bool 
    {
        $userRoles = $this->roles->getUserRoles($user);
        
        foreach ($permissions as $permission) {
            if (!$this->checkPermission($userRoles, $permission)) {
                return false;
            }
        }
        
        return true;
    }

    private function checkPermission(array $roles, string $permission): bool 
    {
        foreach ($roles as $role) {
            if ($this->permissions->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }
}

class SecurityContext
{
    private User $user;
    private string $token;
    private array $session;
    private \DateTimeImmutable $createdAt;

    public function isValid(): bool 
    {
        return $this->user !== null && 
               $this->token !== null && 
               $this->session !== null &&
               $this->createdAt->add(new \DateInterval('PT1H')) > new \DateTimeImmutable();
    }

    public function getUser(): User 
    {
        return $this->user;
    }

    public function getToken(): string 
    {
        return $this->token;
    }
}
