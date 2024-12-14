<?php

namespace App\Security\Auth;

class CriticalAuthenticationManager
{
    private SecurityManager $security;
    private TokenManager $tokens;
    private UserRepository $users;
    private AuditLogger $logger;
    private RateLimiter $limiter;

    public function authenticate(array $credentials): AuthResult
    {
        if ($this->limiter->isExceeded('auth', $credentials['ip'])) {
            $this->logger->logFailedAttempt($credentials['ip']);
            throw new RateLimitException('Too many attempts');
        }

        try {
            // Validate credentials
            $user = $this->users->findByCredentials($credentials);
            if (!$user || !$this->verifyCredentials($user, $credentials)) {
                throw new AuthenticationException('Invalid credentials');
            }

            // Generate secure tokens
            $token = $this->tokens->generate($user, [
                'ip' => $credentials['ip'],
                'device' => $credentials['device']
            ]);

            // Log successful authentication
            $this->logger->logSuccessfulAuth($user, $credentials);

            return new AuthResult($user, $token);

        } catch (\Exception $e) {
            $this->limiter->increment('auth', $credentials['ip']);
            $this->logger->logAuthError($e, $credentials);
            throw $e;
        }
    }

    private function verifyCredentials(User $user, array $credentials): bool
    {
        if (!$this->security->verifyHash($credentials['password'], $user->password)) {
            return false;
        }

        if ($user->requires2fa && !$this->verify2FA($user, $credentials)) {
            return false;
        }

        return true;
    }

    private function verify2FA(User $user, array $credentials): bool
    {
        return $this->security->verify2FAToken(
            $credentials['2fa_token'],
            $user->two_factor_secret
        );
    }
}

class TokenManager
{
    private EncryptionService $encryption;
    private TokenRepository $tokens;
    private SecurityConfig $config;

    public function generate(User $user, array $context): Token
    {
        $payload = [
            'user_id' => $user->id,
            'roles' => $user->roles,
            'permissions' => $user->permissions,
            'context' => $context,
            'expires_at' => $this->getExpiryTime()
        ];

        $token = $this->encryption->encrypt(json_encode($payload));
        
        return $this->tokens->create([
            'user_id' => $user->id,
            'token' => $token,
            'context' => $context,
            'expires_at' => $payload['expires_at']
        ]);
    }

    public function validate(string $tokenString): ?Token
    {
        $token = $this->tokens->findByToken($tokenString);
        
        if (!$token || $token->isExpired() || $token->isRevoked()) {
            return null;
        }

        $payload = json_decode(
            $this->encryption->decrypt($token->token),
            true
        );

        if (!$this->validateTokenPayload($payload)) {
            return null;
        }

        return $token;
    }

    private function validateTokenPayload(array $payload): bool
    {
        return isset($payload['user_id']) &&
               isset($payload['expires_at']) &&
               $payload['expires_at'] > time();
    }

    private function getExpiryTime(): int
    {
        return time() + $this->config->get('auth.token_lifetime', 3600);
    }
}

class CriticalValidationService
{
    private SecurityManager $security;
    private ValidationRules $rules;
    private AuditLogger $logger;

    public function validate(array $data, array $rules): array
    {
        $violations = [];

        foreach ($rules as $field => $ruleset) {
            if (!$this->validateField($data[$field] ?? null, $ruleset)) {
                $violations[] = $field;
            }
        }

        if (!empty($violations)) {
            $this->logger->logValidationFailure($data, $rules, $violations);
            throw new ValidationException('Validation failed', $violations);
        }

        return $this->sanitizeData($data, $rules);
    }

    private function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$this->rules->check($rule, $value)) {
                return false;
            }
        }
        return true;
    }

    private function sanitizeData(array $data, array $rules): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (isset($rules[$key])) {
                $sanitized[$key] = $this->security->sanitize($value);
            }
        }
        return $sanitized;
    }
}
