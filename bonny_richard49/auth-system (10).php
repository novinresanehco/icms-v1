<?php

namespace App\Core\Auth;

use App\Core\Security\{CoreSecurityManager, EncryptionService};
use App\Core\Interfaces\{AuthManagerInterface, ValidationServiceInterface};
use App\Core\Exceptions\{AuthenticationException, AuthorizationException};
use Illuminate\Support\Facades\{Cache, DB, Log};

class AuthManager implements AuthManagerInterface
{
    private CoreSecurityManager $security;
    private EncryptionService $encryption;
    private ValidationServiceInterface $validator;
    private array $config;

    public function __construct(
        CoreSecurityManager $security,
        EncryptionService $encryption,
        ValidationServiceInterface $validator,
        array $config = []
    ) {
        $this->security = $security;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthToken
    {
        try {
            // Validate credentials
            $this->validateCredentials($credentials);

            // Verify user
            $user = $this->verifyUser($credentials);

            // Check MFA if required
            if ($this->requiresMfa($user)) {
                $this->verifyMfa($credentials, $user);
            }

            // Generate session token
            $token = $this->generateToken($user);

            // Create session
            $this->createSession($token, $user);

            // Log successful authentication
            $this->logAuthSuccess($user);

            return $token;

        } catch (\Exception $e) {
            $this->handleAuthFailure($credentials, $e);
            throw $e;
        }
    }

    public function validateToken(string $token): AuthToken
    {
        try {
            // Decode token
            $decoded = $this->decodeToken($token);

            // Verify signature
            if (!$this->verifyTokenSignature($decoded)) {
                throw new AuthenticationException('Invalid token signature');
            }

            // Check expiration
            if ($this->isTokenExpired($decoded)) {
                throw new AuthenticationException('Token expired');
            }

            // Verify session
            $session = $this->verifySession($decoded);

            // Refresh token if needed
            if ($this->shouldRefreshToken($decoded)) {
                return $this->refreshToken($decoded, $session);
            }

            return new AuthToken($decoded);

        } catch (\Exception $e) {
            $this->handleTokenFailure($token, $e);
            throw $e;
        }
    }

    public function authorize(string $token, string $permission): bool
    {
        try {
            // Validate token
            $authToken = $this->validateToken($token);

            // Get user permissions
            $permissions = $this->getUserPermissions($authToken->getUserId());

            // Check permission
            if (!$this->checkPermission($permissions, $permission)) {
                throw new AuthorizationException('Permission denied');
            }

            // Log authorization
            $this->logAuthorizationSuccess($authToken, $permission);

            return true;

        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($token, $permission, $e);
            throw $e;
        }
    }

    public function logout(string $token): bool
    {
        try {
            // Validate token
            $decoded = $this->decodeToken($token);

            // Invalidate session
            $this->invalidateSession($decoded);

            // Log logout
            $this->logLogout($decoded);

            return true;

        } catch (\Exception $e) {
            Log::warning('Logout failed', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 10) . '...'
            ]);
            return false;
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        $rules = [
            'username' => 'required|string|min:3',
            'password' => 'required|string|min:8'
        ];

        if (!$this->validator->validateInput($credentials)) {
            throw new AuthenticationException('Invalid credentials format');
        }
    }

    protected function verifyUser(array $credentials): User
    {
        // Get user
        $user = DB::table('users')
            ->where('username', $credentials['username'])
            ->first();

        if (!$user) {
            throw new AuthenticationException('User not found');
        }

        // Verify password
        if (!$this->verifyPassword($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid password');
        }

        // Check account status
        if (!$this->isAccountActive($user)) {
            throw new AuthenticationException('Account inactive');
        }

        return $user;
    }

    protected function requiresMfa(User $user): bool
    {
        return $user->mfa_enabled || 
               $this->config['force_mfa'] ?? false ||
               $this->isHighRiskUser($user);
    }

    protected function verifyMfa(array $credentials, User $user): void
    {
        if (!isset($credentials['mfa_code'])) {
            throw new AuthenticationException('MFA code required');
        }

        if (!$this->verifyMfaCode($credentials['mfa_code'], $user)) {
            throw new AuthenticationException('Invalid MFA code');
        }
    }

    protected function generateToken(User $user): AuthToken
    {
        $payload = [
            'user_id' => $user->id,
            'username' => $user->username,
            'roles' => $user->roles,
            'issued_at' => time(),
            'expires_at' => time() + ($this->config['token_ttl'] ?? 3600),
            'session_id' => uniqid('sess_', true)
        ];

        // Sign payload
        $signature = $this->signPayload($payload);
        $payload['signature'] = $signature;

        // Encrypt token
        $token = $this->encryption->encrypt(json_encode($payload));

        return new AuthToken($payload, $token);
    }

    protected function createSession(AuthToken $token, User $user): void
    {
        $session = [
            'token' => $token->getEncoded(),
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'expires_at' => now()->addSeconds($this->config['token_ttl'] ?? 3600)
        ];

        DB::table('sessions')->insert($session);

        // Cache session data
        $cacheKey = "session:{$token->getSessionId()}";
        Cache::put($cacheKey, $session, $this->config['token_ttl'] ?? 3600);
    }

    protected function decodeToken(string $token): array
    {
        try {
            $decrypted = $this->encryption->decrypt($token);
            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            throw new AuthenticationException('Invalid token format');
        }
    }

    protected function verifyTokenSignature(array $decoded): bool
    {
        $signature = $decoded['signature'] ?? null;
        unset($decoded['signature']);

        return $signature === $this->signPayload($decoded);
    }

    protected function isTokenExpired(array $decoded): bool
    {
        return ($decoded['expires_at'] ?? 0) < time();
    }

    protected function verifySession(array $decoded): array
    {
        $cacheKey = "session:{$decoded['session_id']}";
        $session = Cache::get($cacheKey);

        if (!$session) {
            throw new AuthenticationException('Session not found');
        }

        if ($session['expires_at'] < now()) {
            throw new AuthenticationException('Session expired');
        }

        return $session;
    }

    protected function shouldRefreshToken(array $decoded): bool
    {
        $refreshThreshold = $this->config['refresh_threshold'] ?? 300; // 5 minutes
        return ($decoded['expires_at'] - time()) < $refreshThreshold;
    }

    protected function refreshToken(array $decoded, array $session): AuthToken
    {
        // Generate new token
        $user = DB::table('users')->find($decoded['user_id']);
        $newToken = $this->generateToken($user);

        // Update session
        $this->updateSession($session['session_id'], $newToken);

        return $newToken;
    }

    protected function invalidateSession(array $decoded): void
    {
        // Remove from database
        DB::table('sessions')
            ->where('session_id', $decoded['session_id'])
            ->delete();

        // Remove from cache
        Cache::forget("session:{$decoded['session_id']}");
    }

    protected function getUserPermissions(int $userId): array
    {
        $cacheKey = "user_permissions:$userId";

        return Cache::remember($cacheKey, 300, function() use ($userId) {
            // Get user roles
            $roles = DB::table('user_roles')
                ->where('user_id', $userId)
                ->pluck('role_id');

            // Get role permissions
            return DB::table('role_permissions')
                ->whereIn('role_id', $roles)
                ->pluck('permission')
                ->toArray();
        });
    }

    protected function checkPermission(array $userPermissions, string $required): bool
    {
        if (in_array('*', $userPermissions)) {
            return true;
        }

        return in_array($required, $userPermissions);
    }

    protected function verifyPassword(string $input, string $hash): bool
    {
        return password_verify($input, $hash);
    }

    protected function isAccountActive(User $user): bool
    {
        return $user->status === 'active' && 
               (!$user->locked_until || $user->locked_until < now());
    }

    protected function isHighRiskUser(User $user): bool
    {
        return in_array('admin', $user->roles) ||
               in_array($user->ip_address, $this->config['high_risk_ips'] ?? []);
    }

    protected function verifyMfaCode(string $code, User $user): bool
    {
        // Implement MFA verification logic
        return true; // Placeholder
    }

    protected function signPayload(array $payload): string
    {
        $data = json_encode($payload);
        return hash_hmac('sha256', $data, $this->config['token_secret']);
    }

    protected function updateSession(string $sessionId, AuthToken $token): void
    {
        $session = [
            'token' => $token->getEncoded(),
            'expires_at' => now()->addSeconds($this->config['token_ttl'] ?? 3600)
        ];

        DB::table('sessions')
            ->where('session_id', $sessionId)
            ->update($session);

        Cache::put("session:$sessionId", $session, $this->config['token_ttl'] ?? 3600);
    }

    protected function logAuthSuccess(User $user): void
    {
        Log::info('Authentication successful', [
            'user_id' => $user->id,
            'username' => $user->username,
            'ip_address' => request()->ip()
        ]);
    }

    protected function logAuthorizationSuccess(AuthToken $token, string $permission): void
    {
        Log::info('Authorization successful', [
            'user_id' => $token->getUserId(),
            'permission' => $permission,
            'ip_address' => request()->ip()
        ]);
    }

    protected function logLogout(array $decoded): void
    {
        Log::info('Logout successful', [
            'user_id' => $decoded['user_id'],
            'session_id' => $decoded['session_id']
        ]);
    }

    protected function handleAuthFailure(array $credentials, \Exception $e): void
    {
        Log::warning('Authentication failed', [
            'username' => $credentials['username'] ?? 'unknown',
            'ip_address' => request()->ip(),
            'error' => $e->getMessage()
        ]);
    }

    protected function handleTokenFailure(string $token, \Exception $e): void
    {
        Log::warning('Token validation failed', [
            'token' => substr($token, 0, 10) . '...',
            'error' => $e->getMessage()
        ]);
    }

    protected function handleAuthorizationFailure(
        string $token,
        string $permission,
        \Exception $e
    ): void {
        Log::warning('Authorization failed', [
            'token' => substr($token, 0, 10) . '...',
            'permission' => $permission,
            'error' => $e->getMessage()
        ]);
    }
}

class AuthToken
{
    private array $payload;
    private ?string $encoded;

    public function __construct(array $payload, ?string $encoded = null)
    {
        $this->payload = $payload;
        $this->encoded = $encoded;
    }

    public function getUserId(): int
    {
        return $this->payload['user_id'];
    }

    public function getSessionId(): string
    {
        return $this->payload['session_id'];
    }

    public function getEncoded(): string
    {
        return $this->encoded;
    }
}

interface User
{
    public function getId(): int;
    public function getUsername(): string;
    public function getRoles(): array;
}
