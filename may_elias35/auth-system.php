<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Exceptions\AuthenticationException;

class AuthenticationManager
{
    private TokenManager $tokens;
    private UserRepository $users;
    private SecurityLogger $logger;

    public function __construct(
        TokenManager $tokens,
        UserRepository $users,
        SecurityLogger $logger
    ) {
        $this->tokens = $tokens;
        $this->users = $users;
        $this->logger = $logger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $user = $this->users->findByEmail($credentials['email']);

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->tokens->create($user);
            $this->logger->logSuccess('auth.login', $user->id);

            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            $this->logger->logFailure('auth.login', $credentials['email'], $e);
            throw $e;
        }
    }

    public function validateToken(string $token): bool
    {
        try {
            return $this->tokens->verify($token);
        } catch (\Exception $e) {
            $this->logger->logFailure('auth.token_validation', $token, $e);
            return false;
        }
    }

    public function logout(string $token): void
    {
        $this->tokens->revoke($token);
    }
}

class TokenManager
{
    private string $key;
    private int $expiration = 3600;

    public function create(User $user): string
    {
        $payload = [
            'uid' => $user->id,
            'exp' => time() + $this->expiration,
            'jti' => Str::random(32)
        ];

        return $this->encode($payload);
    }

    public function verify(string $token): bool
    {
        try {
            $payload = $this->decode($token);
            return $payload && $payload['exp'] > time();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function revoke(string $token): void
    {
        Cache::put("revoked_token:$token", true, $this->expiration);
    }

    private function encode(array $payload): string
    {
        return JWT::encode($payload, $this->key);
    }

    private function decode(string $token): ?array
    {
        return JWT::decode($token, $this->key);
    }
}

class UserRepository
{
    private DB $db;

    public function findByEmail(string $email): ?User
    {
        return DB::table('users')->where('email', $email)->first();
    }

    public function findById(int $id): ?User
    {
        return DB::table('users')->find($id);
    }
}

class SecurityLogger
{
    public function logSuccess(string $event, int $userId): void
    {
        Log::info($event, [
            'user_id' => $userId,
            'ip' => request()->ip(),
            'timestamp' => now()
        ]);
    }

    public function logFailure(string $event, string $identifier, \Exception $e): void
    {
        Log::error($event, [
            'identifier' => $identifier,
            'error' => $e->getMessage(),
            'ip' => request()->ip(),
            'timestamp' => now()
        ]);
    }
}

class AuthenticationMiddleware
{
    private AuthenticationManager $auth;

    public function handle($request, $next)
    {
        $token = $request->bearerToken();

        if (!$token || !$this->auth->validateToken($token)) {
            throw new AuthenticationException('Invalid or expired token');
        }

        return $next($request);
    }
}

class AuthController extends Controller
{
    private AuthenticationManager $auth;

    public function login(LoginRequest $request)
    {
        $result = $this->auth->authenticate($request->validated());
        return response()->json(['token' => $result->token]);
    }

    public function logout(Request $request)
    {
        $this->auth->logout($request->bearerToken());
        return response()->noContent();
    }
}
