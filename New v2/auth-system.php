<?php

namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface 
{
    private TokenManager $tokens;
    private SessionManager $sessions;
    private UserRepository $users;
    private AuditLogger $logger;

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        try {
            $user = $this->validateCredentials($credentials);
            $token = $this->tokens->generate($user);
            $session = $this->sessions->create($user, $token);
            
            DB::commit();
            $this->logger->logAuthentication($user);
            
            return new AuthResult($user, $token, $session);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailedAuthentication($credentials);
            throw $e;
        }
    }

    public function validateSession(string $token): SessionValidationResult
    {
        $session = $this->sessions->validate($token);
        if (!$session->isValid()) {
            $this->logger->logInvalidSession($token);
            throw new InvalidSessionException();
        }
        return new SessionValidationResult($session);
    }

    private function validateCredentials(array $credentials): User
    {
        $user = $this->users->findByCredentials($credentials);
        if (!$user || !$user->validatePassword($credentials['password'])) {
            throw new InvalidCredentialsException();
        }
        return $user;
    }
}

class SessionManager implements SessionInterface
{
    private CacheManager $cache;
    private ConfigManager $config;
    private AuditLogger $logger;

    public function create(User $user, string $token): Session
    {
        $session = new Session([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => $this->getExpirationTime(),
            'data' => $this->getInitialData($user)
        ]);

        $this->cache->put(
            $this->getSessionKey($token),
            $session,
            $this->config->get('session.lifetime')
        );

        return $session;
    }

    public function validate(string $token): SessionValidationResult
    {
        $session = $this->cache->get($this->getSessionKey($token));
        
        if (!$session || $session->isExpired()) {
            return new SessionValidationResult(false);
        }

        $this->extendSession($session);
        return new SessionValidationResult(true, $session);
    }

    private function extendSession(Session $session): void
    {
        if ($this->shouldExtendSession($session)) {
            $session->extend($this->getExpirationTime());
            $this->cache->put(
                $this->getSessionKey($session->token),
                $session,
                $this->config->get('session.lifetime')
            );
        }
    }

    private function shouldExtendSession(Session $session): bool
    {
        $lifetime = $this->config->get('session.lifetime');
        $threshold = $lifetime * 0.2; // Extend when 20% lifetime remains
        
        return $session->getTimeToExpiration() < $threshold;
    }

    private function getSessionKey(string $token): string
    {
        return "session:$token";
    }

    private function getExpirationTime(): int
    {
        return time() + $this->config->get('session.lifetime');
    }

    private function getInitialData(User $user): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'permissions' => $user->getPermissions(),
            'created_at' => time()
        ];
    }
}

class TokenManager implements TokenInterface
{
    private string $key;
    private string $algorithm;
    private int $lifetime;

    public function generate(User $user): string
    {
        $payload = [
            'sub' => $user->id,
            'role' => $user->role_id,
            'iat' => time(),
            'exp' => time() + $this->lifetime,
            'jti' => Str::random(32)
        ];

        return JWT::encode($payload, $this->key, $this->algorithm);
    }

    public function validate(string $token): TokenValidationResult
    {
        try {
            $payload = JWT::decode($token, $this->key, [$this->algorithm]);
            return new TokenValidationResult(true, $payload);
        } catch (\Exception $e) {
            return new TokenValidationResult(false);
        }
    }

    public function refresh(string $token): string
    {
        $result = $this->validate($token);
        if (!$result->isValid()) {
            throw new InvalidTokenException();
        }

        $payload = (array)$result->getPayload();
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->lifetime;
        $payload['jti'] = Str::random(32);

        return JWT::encode($payload, $this->key, $this->algorithm);
    }
}

class Session
{
    private int $userId;
    private string $token;
    private int $expiresAt;
    private array $data;
    private array $changes = [];

    public function __construct(array $attributes)
    {
        $this->userId = $attributes['user_id'];
        $this->token = $attributes['token'];
        $this->expiresAt = $attributes['expires_at'];
        $this->data = $attributes['data'] ?? [];
    }

    public function isValid(): bool
    {
        return time() < $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return !$this->isValid();
    }

    public function getTimeToExpiration(): int
    {
        return max(0, $this->expiresAt - time());
    }

    public function extend(int $newExpiration): void
    {
        $this->expiresAt = $newExpiration;
        $this->changes[] = 'expiration_extended';
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->changes[] = "data_changed:$key";
    }

    public function getChanges(): array
    {
        return $this->changes;
    }
}
