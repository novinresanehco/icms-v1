<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Interfaces\{AuthenticationInterface, SecurityInterface};

class AuthenticationManager implements AuthenticationInterface
{
    private TokenManager $tokens;
    private SessionManager $sessions;
    private AuditLogger $audit;
    private TwoFactorProvider $twoFactor;

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        try {
            // Validate credentials
            $user = $this->validateCredentials($credentials);
            
            // Require 2FA if enabled
            if ($user->hasTwoFactorEnabled()) {
                $this->twoFactor->challenge($user);
            }
            
            // Generate tokens
            $token = $this->tokens->generate($user);
            
            // Create secure session
            $this->sessions->create($user, $token);
            
            DB::commit();
            
            // Log successful auth
            $this->audit->logAuthentication($user);
            
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateSession(string $token): bool
    {
        $session = $this->sessions->get($token);
        
        if (!$session || $session->isExpired()) {
            return false;
        }
        
        if ($session->requiresRefresh()) {
            $this->sessions->refresh($session);
        }
        
        return true;
    }

    public function logout(string $token): void
    {
        $this->sessions->invalidate($token);
        $this->tokens->revoke($token);
    }
}

class SessionManager
{
    private const MAX_LIFETIME = 3600;
    private const REFRESH_AFTER = 900;

    public function create(User $user, string $token): Session
    {
        return Session::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addSeconds(self::MAX_LIFETIME),
            'refresh_at' => now()->addSeconds(self::REFRESH_AFTER)
        ]);
    }

    public function refresh(Session $session): void
    {
        $session->update([
            'expires_at' => now()->addSeconds(self::MAX_LIFETIME),
            'refresh_at' => now()->addSeconds(self::REFRESH_AFTER)
        ]);
    }

    public function invalidate(string $token): void
    {
        Session::where('token', $token)->delete();
    }
}

class TokenManager
{
    private const TOKEN_LENGTH = 64;

    public function generate(User $user): string
    {
        $token = $this->generateSecureToken();
        
        Token::create([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'expires_at' => now()->addDay()
        ]);
        
        return $token;
    }

    public function revoke(string $token): void
    {
        Token::where('token', Hash::make($token))->delete();
    }

    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }
}

class TwoFactorProvider
{
    private const CODE_LENGTH = 6;
    private const CODE_TTL = 600;

    public function challenge(User $user): void
    {
        $code = $this->generateCode();
        
        Cache::put(
            $this->getCodeKey($user),
            Hash::make($code),
            self::CODE_TTL
        );
        
        $this->sendCode($user, $code);
    }

    public function verify(User $user, string $code): bool
    {
        $hashedCode = Cache::get($this->getCodeKey($user));
        
        if (!$hashedCode) {
            return false;
        }
        
        return Hash::check($code, $hashedCode);
    }

    private function generateCode(): string
    {
        return str_pad(
            (string) random_int(0, pow(10, self::CODE_LENGTH) - 1),
            self::CODE_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }

    private function getCodeKey(User $user): string
    {
        return "2fa_code:{$user->id}";
    }
}

class AccessControl
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $audit;

    public function authorize(User $user, string $permission): bool
    {
        if (!$this->hasPermission($user, $permission)) {
            $this->audit->logUnauthorizedAccess($user, $permission);
            return false;
        }

        $this->audit->logAuthorizedAccess($user, $permission);
        return true;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        $role = $this->roles->getRole($user);
        return $this->permissions->hasPermission($role, $permission);
    }
}

class AuditLogger
{
    public function logAuthentication(User $user): void
    {
        SecurityLog::create([
            'user_id' => $user->id,
            'type' => 'authentication',
            'status' => 'success',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function logAuthenticationFailure(array $credentials): void
    {
        SecurityLog::create([
            'type' => 'authentication',
            'status' => 'failure',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'details' => json_encode([
                'username' => $credentials['username'] ?? null
            ])
        ]);
    }
}
