<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use App\Core\Interfaces\AuthManagerInterface;
use App\Core\Security\TwoFactorAuthentication;

class AuthenticationManager implements AuthManagerInterface
{
    private SecurityManager $security;
    private TokenManager $tokens;
    private TwoFactorAuthentication $twoFactor;
    private AuditLogger $audit;
    private RateLimiter $limiter;

    public function __construct(
        SecurityManager $security,
        TokenManager $tokens,
        TwoFactorAuthentication $twoFactor,
        AuditLogger $audit,
        RateLimiter $limiter
    ) {
        $this->security = $security;
        $this->tokens = $tokens;
        $this->twoFactor = $twoFactor;
        $this->audit = $audit;
        $this->limiter = $limiter;
    }

    public function authenticate(array $credentials): AuthenticationResult
    {
        if ($this->limiter->tooManyAttempts($credentials['username'])) {
            throw new AuthenticationException('Too many attempts');
        }

        return $this->security->executeCriticalOperation(
            new AuthenticationOperation(
                $credentials,
                $this->tokens,
                $this->twoFactor
            ),
            new SecurityContext('system')
        );
    }

    public function verifyTwoFactor(string $userId, string $code): bool
    {
        return $this->security->executeCriticalOperation(
            new TwoFactorVerificationOperation($userId, $code, $this->twoFactor),
            new SecurityContext('system')
        );
    }

    public function validateToken(string $token): TokenValidationResult
    {
        return $this->security->executeCriticalOperation(
            new TokenValidationOperation($token, $this->tokens),
            new SecurityContext('system')
        );
    }

    public function revokeToken(string $token): void
    {
        $this->security->executeCriticalOperation(
            new TokenRevocationOperation($token, $this->tokens),
            new SecurityContext('system')
        );
    }

    public function refreshToken(string $token): TokenResult
    {
        return $this->security->executeCriticalOperation(
            new TokenRefreshOperation($token, $this->tokens),
            new SecurityContext('system')
        );
    }
}

class AuthenticationOperation implements CriticalOperation
{
    private array $credentials;
    private TokenManager $tokens;
    private TwoFactorAuthentication $twoFactor;

    public function __construct(
        array $credentials,
        TokenManager $tokens,
        TwoFactorAuthentication $twoFactor
    ) {
        $this->credentials = $credentials;
        $this->tokens = $tokens;
        $this->twoFactor = $twoFactor;
    }

    public function execute(): AuthenticationResult
    {
        $user = User::where('username', $this->credentials['username'])->first();

        if (!$user || !Hash::check($this->credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->requires_two_factor && !$this->twoFactor->isVerified($user->id)) {
            $this->twoFactor->generate($user->id);
            return new AuthenticationResult(null, true);
        }

        $token = $this->tokens->generate($user, [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        return new AuthenticationResult($token, false);
    }

    public function getValidationRules(): array
    {
        return [
            'username' => 'required|string',
            'password' => 'required|string'
        ];
    }

    public function getData(): array
    {
        return $this->credentials;
    }

    public function getRequiredPermissions(): array
    {
        return [];
    }
}

class TokenValidationOperation implements CriticalOperation
{
    private string $token;
    private TokenManager $tokens;

    public function __construct(string $token, TokenManager $tokens)
    {
        $this->token = $token;
        $this->tokens = $tokens;
    }

    public function execute(): TokenValidationResult
    {
        $validation = $this->tokens->validate($this->token);

        if (!$validation->isValid()) {
            throw new AuthenticationException('Invalid token');
        }

        if ($validation->isExpired()) {
            throw new AuthenticationException('Token expired');
        }

        if ($validation->requiresRefresh()) {
            return new TokenValidationResult(true, true);
        }

        return new TokenValidationResult(true, false);
    }

    public function getValidationRules(): array
    {
        return [];
    }

    public function getData(): array
    {
        return ['token' => $this->token];
    }

    public function getRequiredPermissions(): array
    {
        return [];
    }
}

class TokenManager
{
    private const TOKEN_LIFETIME = 3600;
    private const REFRESH_THRESHOLD = 300;

    public function generate(User $user, array $context = []): string
    {
        $token = bin2hex(random_bytes(32));
        
        DB::table('access_tokens')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'context' => json_encode($context),
            'expires_at' => now()->addSeconds(self::TOKEN_LIFETIME),
            'created_at' => now()
        ]);

        return $token;
    }

    public function validate(string $token): TokenValidation
    {
        $record = DB::table('access_tokens')
            ->where('token', hash('sha256', $token))
            ->where('revoked', false)
            ->first();

        if (!$record) {
            return new TokenValidation(false);
        }

        $expiresAt = new \DateTime($record->expires_at);
        $now = new \DateTime();

        if ($now > $expiresAt) {
            return new TokenValidation(false, true);
        }

        $refreshNeeded = ($expiresAt->getTimestamp() - $now->getTimestamp()) < self::REFRESH_THRESHOLD;

        return new TokenValidation(true, false, $refreshNeeded);
    }

    public function revoke(string $token): void
    {
        DB::table('access_tokens')
            ->where('token', hash('sha256', $token))
            ->update(['revoked' => true]);
    }
}
