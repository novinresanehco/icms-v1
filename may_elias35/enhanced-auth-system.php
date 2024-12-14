<?php

namespace App\Core\Auth;

class AuthenticationService
{
    protected UserRepository $users;
    protected TokenService $tokens;
    protected RateLimiter $limiter;
    protected SecurityLogger $logger;

    public function authenticate(array $credentials): AuthResult
    {
        if (!$this->limiter->attempt($credentials['ip'])) {
            $this->logger->logFailedAttempt($credentials['ip']);
            throw new RateLimitException('Too many attempts');
        }

        try {
            $user = $this->users->findByEmail($credentials['email']);
            
            if (!$user || !$this->verifyPassword($credentials['password'], $user)) {
                throw new AuthenticationException('Invalid credentials');
            }

            $this->limiter->reset($credentials['ip']);
            $token = $this->tokens->generate($user);
            
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            $this->logger->logAuthFailure($e, $credentials);
            throw $e;
        }
    }

    protected function verifyPassword(string $password, User $user): bool
    {
        return password_verify(
            $password . $user->password_salt,
            $user->password_hash
        );
    }
}

class TokenService
{
    protected string $key;
    protected int $lifetime = 3600;
    protected string $algorithm = 'HS256';

    public function generate(User $user): string
    {
        $payload = [
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + $this->lifetime,
            'jti' => bin2hex(random_bytes(16))
        ];

        return JWT::encode($payload, $this->key, $this->algorithm);
    }

    public function verify(string $token): ?array
    {
        try {
            $payload = JWT::decode(
                $token,
                $this->key,
                [$this->algorithm]
            );

            if ($payload->exp < time()) {
                throw new TokenExpiredException();
            }

            return (array)$payload;
        } catch (\Exception $e) {
            throw new InvalidTokenException($e->getMessage());
        }
    }

    public function refresh(string $token): string
    {
        $payload = $this->verify($token);
        $user = User::findOrFail($payload['sub']);
        return $this->generate($user);
    }
}

class RateLimiter
{
    protected CacheManager $cache;
    protected int $maxAttempts = 5;
    protected int $decayMinutes = 30;

    public function attempt(string $key): bool
    {
        $attempts = (int)$this->cache->get($this->key($key), 0);

        if ($attempts >= $this->maxAttempts) {
            return false;
        }

        $this->cache->put(
            $this->key($key),
            $attempts + 1,
            $this->decayMinutes * 60
        );

        return true;
    }

    public function reset(string $key): void
    {
        $this->cache->forget($this->key($key));
    }

    protected function key(string $key): string
    {
        return 'auth_attempts:' . sha1($key);
    }
}

class UserRepository
{
    protected User $model;
    protected HashManager $hash;

    public function create(array $data): User
    {
        $salt = bin2hex(random_bytes(16));
        
        return DB::transaction(function() use ($data, $salt) {
            return $this->model->create([
                'email' => $data['email'],
                'password_hash' => $this->hash->make($data['password'] . $salt),
                'password_salt' => $salt,
                'role' => $data['role'] ?? 'user',
                'status' => 'active'
            ]);
        });
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model
            ->where('email', $email)
            ->where('status', 'active')
            ->first();
    }
}

class SecurityLogger
{
    protected LogManager $log;

    public function logFailedAttempt(string $ip): void
    {
        $this->log->warning('Failed authentication attempt', [
            'ip' => $ip,
            'time' => time()
        ]);
    }

    public function logAuthFailure(\Exception $e, array $context): void
    {
        $this->log->error('Authentication error', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'context' => $context
        ]);
    }
}

class AuthMiddleware
{
    protected TokenService $tokens;
    protected SecurityLogger $logger;

    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();
            
            if (!$token) {
                throw new AuthenticationException('No token provided');
            }

            $payload = $this->tokens->verify($token);
            $request->auth = $payload;

            return $next($request);
        } catch (\Exception $e) {
            $this->logger->logAuthFailure($e, [
                'ip' => $request->ip(),
                'uri' => $request->uri()
            ]);
            throw $e;
        }
    }
}
