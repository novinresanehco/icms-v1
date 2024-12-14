<?php

namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenManager $tokens;
    private PasswordHasher $hasher;
    private AuditLogger $logger;

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            new AuthenticateUserOperation(
                $credentials,
                $this->users,
                $this->hasher,
                $this->tokens
            )
        );
    }

    public function validate(string $token): bool
    {
        try {
            $payload = $this->tokens->verify($token);
            $user = $this->users->findById($payload['sub']);
            return $user && !$user->isBlocked();
        } catch (TokenException $e) {
            $this->logger->logFailedValidation($token, $e);
            return false;
        }
    }
}

class AuthorizationManager implements AuthorizationInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $logger;

    public function checkPermission(User $user, string $permission): bool
    {
        $result = $this->roles->hasPermission($user->role, $permission);
        $this->logger->logPermissionCheck($user, $permission, $result);
        return $result;
    }

    public function validateAccess(User $user, Resource $resource): bool
    {
        return $this->security->executeCriticalOperation(
            new ValidateAccessOperation($user, $resource, $this->permissions)
        );
    }
}

class AuthenticateUserOperation implements CriticalOperation
{
    private array $credentials;
    private UserRepository $users;
    private PasswordHasher $hasher;
    private TokenManager $tokens;

    public function execute(): AuthResult
    {
        $user = $this->users->findByUsername($this->credentials['username']);
        
        if (!$user || !$this->hasher->verify(
            $this->credentials['password'],
            $user->password
        )) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->isBlocked() || $user->requiresReverification()) {
            throw new AccountException('Account requires verification');
        }

        $token = $this->tokens->generate([
            'sub' => $user->id,
            'role' => $user->role,
        ]);

        return new AuthResult($user, $token);
    }

    public function getRequiredPermissions(): array
    {
        return ['auth.authenticate'];
    }
}

class PasswordHasher
{
    private string $pepper;
    private int $algorithm = PASSWORD_ARGON2ID;
    private array $options = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ];

    public function hash(string $password): string
    {
        return password_hash(
            $this->pepper . $password,
            $this->algorithm,
            $this->options
        );
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($this->pepper . $password, $hash);
    }
}

class TokenManager
{
    private string $key;
    private int $ttl;
    private array $allowedAlgorithms = ['ES384'];

    public function generate(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES384'
        ];

        $payload = array_merge($payload, [
            'iat' => time(),
            'exp' => time() + $this->ttl,
            'jti' => bin2hex(random_bytes(16))
        ]);

        return $this->sign($header, $payload);
    }

    public function verify(string $token): array
    {
        [$headerB64, $payloadB64, $signature] = explode('.', $token);
        
        $header = json_decode(base64_decode($headerB64), true);
        if (!in_array($header['alg'], $this->allowedAlgorithms)) {
            throw new TokenException('Invalid algorithm');
        }

        if (!$this->verifySignature($headerB64 . '.' . $payloadB64, $signature)) {
            throw new TokenException('Invalid signature');
        }

        $payload = json_decode(base64_decode($payloadB64), true);
        if ($payload['exp'] < time()) {
            throw new TokenException('Token expired');
        }

        return $payload;
    }

    private function sign(array $header, array $payload): string
    {
        $headerB64 = base64_encode(json_encode($header));
        $payloadB64 = base64_encode(json_encode($payload));
        
        $signature = $this->generateSignature($headerB64 . '.' . $payloadB64);
        
        return $headerB64 . '.' . $payloadB64 . '.' . base64_encode($signature);
    }

    private function generateSignature(string $data): string
    {
        openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA384);
        return $signature;
    }

    private function verifySignature(string $data, string $signature): bool
    {
        return openssl_verify(
            $data,
            base64_decode($signature),
            $this->key,
            OPENSSL_ALGO_SHA384
        ) === 1;
    }
}
