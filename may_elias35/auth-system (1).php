<?php

namespace App\Core\Auth;

use App\Core\Security\CoreSecurityManager;
use App\Core\Auth\Services\{
    TokenManager,
    SessionManager,
    TwoFactorAuth,
    RateLimiter
};
use Illuminate\Support\Facades\{Hash, Event};
use App\Core\Exceptions\AuthenticationException;

class AuthenticationManager implements AuthenticationInterface 
{
    private CoreSecurityManager $security;
    private TokenManager $tokens;
    private SessionManager $sessions;
    private TwoFactorAuth $twoFactor;
    private RateLimiter $rateLimiter;

    public function __construct(
        CoreSecurityManager $security,
        TokenManager $tokens,
        SessionManager $sessions,
        TwoFactorAuth $twoFactor,
        RateLimiter $rateLimiter
    ) {
        $this->security = $security;
        $this->tokens = $tokens;
        $this->sessions = $sessions;
        $this->twoFactor = $twoFactor;
        $this->rateLimiter = $rateLimiter;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation($credentials, $this),
            new SecurityContext('authenticate', 'auth')
        );
    }

    public function validateSession(string $token): bool 
    {
        if (!$this->rateLimiter->attempt('session.validate')) {
            throw new RateLimitException();
        }

        return $this->sessions->validate($token);
    }

    public function verify2FA(string $code): bool 
    {
        if (!$this->rateLimiter->attempt('2fa.verify')) {
            throw new RateLimitException();
        }

        return $this->twoFactor->verify($code);
    }

    public function logout(string $token): void 
    {
        $this->security->executeCriticalOperation(
            new LogoutOperation($token, $this->sessions),
            new SecurityContext('logout', 'auth')
        );
    }

    protected function validateCredentials(User $user, array $credentials): bool 
    {
        return Hash::check(
            $credentials['password'],
            $user->password
        );
    }
}

class AuthenticationOperation implements CriticalOperation 
{
    private array $credentials;
    private AuthenticationManager $auth;

    public function __construct(array $credentials, AuthenticationManager $auth) 
    {
        $this->credentials = $credentials;
        $this->auth = $auth;
    }

    public function execute(): AuthResult 
    {
        $user = User::where('email', $this->credentials['email'])->first();
        
        if (!$user || !$this->auth->validateCredentials($user, $this->credentials)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->requires2FA && !$this->auth->verify2FA($this->credentials['2fa_code'] ?? '')) {
            throw new AuthenticationException('Invalid 2FA code');
        }

        $token = $this->auth->tokens->create($user);
        $session = $this->auth->sessions->create($token);

        Event::dispatch(new UserAuthenticated($user));

        return new AuthResult($user, $token, $session);
    }

    public function getValidationRules(): array 
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
            '2fa_code' => 'string|size:6'
        ];
    }

    public function getRequiredPermissions(): array 
    {
        return [];
    }

    public function getRateLimitKey(): string 
    {
        return 'auth.attempt.' . $this->credentials['email'];
    }
}

class LogoutOperation implements CriticalOperation 
{
    private string $token;
    private SessionManager $sessions;

    public function __construct(string $token, SessionManager $sessions) 
    {
        $this->token = $token;
        $this->sessions = $sessions;
    }

    public function execute(): bool 
    {
        $session = $this->sessions->find($this->token);
        
        if (!$session) {
            throw new AuthenticationException('Invalid session');
        }

        $this->sessions->invalidate($session);
        Event::dispatch(new UserLoggedOut($session->user));

        return true;
    }

    public function getValidationRules(): array 
    {
        return [
            'token' => 'required|string'
        ];
    }

    public function getRequiredPermissions(): array 
    {
        return [];
    }

    public function getRateLimitKey(): string 
    {
        return 'auth.logout.' . auth()->id();
    }
}

class TokenManager 
{
    public function create(User $user): string 
    {
        return hash_hmac('sha256', 
            $user->id . uniqid() . time(),
            config('app.key')
        );
    }

    public function validate(string $token): bool 
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}

class SessionManager 
{
    public function create(string $token): Session 
    {
        return Session::create([
            'token' => $token,
            'user_id' => auth()->id(),
            'expires_at' => now()->addMinutes(config('auth.session_lifetime'))
        ]);
    }

    public function validate(string $token): bool 
    {
        $session = $this->find($token);
        
        return $session && !$session->isExpired();
    }

    public function invalidate(Session $session): void 
    {
        $session->invalidated_at = now();
        $session->save();
    }

    public function find(string $token): ?Session 
    {
        return Session::where('token', $token)
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}

class TwoFactorAuth 
{
    public function verify(string $code): bool 
    {
        if (!$code) {
            return false;
        }

        $user = auth()->user();
        
        return google2fa_verify($code, $user->two_factor_secret);
    }
}
