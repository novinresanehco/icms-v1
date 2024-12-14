namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\SecurityManager;

class AuthenticationService
{
    protected SecurityManager $security;
    protected UserRepository $users;
    protected TokenManager $tokens;

    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        TokenManager $tokens
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->tokens = $tokens;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $user = $this->validateCredentials($credentials);
            $token = $this->generateToken($user);
            $this->logSuccessfulAuth($user);
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            $this->logFailedAuth($credentials, $e);
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->is_active) {
            throw new AuthenticationException('Account is inactive');
        }

        return $user;
    }

    protected function generateToken(User $user): string
    {
        return $this->tokens->create([
            'user_id' => $user->id,
            'abilities' => $this->getAbilities($user),
            'expires_at' => now()->addMinutes(config('auth.token_lifetime'))
        ]);
    }

    protected function getAbilities(User $user): array
    {
        return $user->roles->flatMap(function ($role) {
            return $role->permissions->pluck('name');
        })->unique()->values()->all();
    }

    public function validateToken(string $token): User
    {
        $payload = $this->tokens->verify($token);
        
        if (!$payload || $payload->isExpired()) {
            throw new AuthenticationException('Invalid or expired token');
        }

        return $this->users->find($payload->user_id);
    }

    protected function logSuccessfulAuth(User $user): void
    {
        Log::info('Authentication successful', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    protected function logFailedAuth(array $credentials, \Exception $e): void
    {
        Log::warning('Authentication failed', [
            'email' => $credentials['email'],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }
}

class TokenManager
{
    public function create(array $payload): string
    {
        $token = bin2hex(random_bytes(32));
        
        Cache::put(
            $this->getTokenKey($token),
            $payload,
            now()->addMinutes(config('auth.token_lifetime'))
        );

        return $token;
    }

    public function verify(string $token): ?TokenPayload
    {
        $payload = Cache::get($this->getTokenKey($token));
        
        if (!$payload) {
            return null;
        }

        return new TokenPayload($payload);
    }

    public function revoke(string $token): void
    {
        Cache::forget($this->getTokenKey($token));
    }

    protected function getTokenKey(string $token): string
    {
        return "auth_token:{$token}";
    }
}

class AuthenticationMiddleware
{
    protected AuthenticationService $auth;

    public function handle($request, $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            throw new AuthenticationException('No token provided');
        }

        $user = $this->auth->validateToken($token);
        auth()->setUser($user);

        return $next($request);
    }
}

class AuthenticationException extends \Exception {}
class TokenPayload 
{
    public function __construct(array $data) 
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);  
    }
}
