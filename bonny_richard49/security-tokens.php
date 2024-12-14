<?php

namespace App\Core\Security;

class TokenManager implements TokenManagerInterface 
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $audit;
    private CacheManager $cache;
    
    public function createToken(User $user, array $claims = []): Token
    {
        $payload = array_merge([
            'user_id' => $user->getId(),
            'issued_at' => time(),
            'expires_at' => time() + config('auth.token_ttl'),
            'permissions' => $user->getPermissions()
        ], $claims);

        $token = new Token(
            $this->encryption->encrypt(json_encode($payload)),
            $payload
        );

        $this->cacheToken($token);
        $this->audit->logTokenCreation($token);

        return $token;
    }

    public function validateToken(string $tokenString): bool 
    {
        try {
            $decrypted = $this->encryption->decrypt($tokenString);
            $payload = json_decode($decrypted, true);

            if (!$this->validator->validateTokenPayload($payload)) {
                return false;
            }

            if ($this->isTokenExpired($payload)) {
                return false;
            }

            if ($this->isTokenRevoked($tokenString)) {
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->audit->logTokenValidationFailure($tokenString, $e);
            return false;
        }
    }

    public function revokeToken(string $tokenString): void
    {
        $this->cache->add(
            $this->getRevokedTokenKey($tokenString),
            true,
            config('auth.revoked_token_ttl')
        );

        $this->audit->logTokenRevocation($tokenString);
    }

    private function cacheToken(Token $token): void
    {
        $this->cache->add(
            $this->getTokenKey($token->getString()),
            $token,
            config('auth.token_ttl')
        );
    }

    private function isTokenExpired(array $payload): bool
    {
        return $payload['expires_at'] < time();
    }

    private function isTokenRevoked(string $tokenString): bool
    {
        return $this->cache->has(
            $this->getRevokedTokenKey($tokenString)
        );
    }

    private function getTokenKey(string $tokenString): string
    {
        return 'token:' . hash('sha256', $tokenString);
    }

    private function getRevokedTokenKey(string $tokenString): string
    {
        return 'revoked:' . hash('sha256', $tokenString);
    }
}

class Token 
{
    private string $tokenString;
    private array $payload;

    public function __construct(string $tokenString, array $payload)
    {
        $this->tokenString = $tokenString;
        $this->payload = $payload;
    }

    public function getString(): string 
    {
        return $this->tokenString;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getClaim(string $claim)
    {
        return $this->payload[$claim] ?? null;
    }

    public function isExpired(): bool
    {
        return $this->payload['expires_at'] < time();
    }
}

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;

    public function encrypt(string $data): string 
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string
    {
        $data = base64_decode($data);
        
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        return openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    public function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }

    public function verify(string $data, string $hash): bool
    {
        return hash_equals(
            $hash,
            $this->hash($data)
        );
    }
}

class SecurityService implements SecurityInterface 
{
    private TokenManager $tokens;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function authenticate(array $credentials): Token
    {
        $this->validator->validate($credentials, [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        $user = $this->findAndValidateUser($credentials);
        
        return $this->tokens->createToken($user);
    }

    public function validateRequest(Request $request): bool
    {
        $token = $this->extractToken($request);
        
        if (!$token) {
            return false;
        }

        return $this->tokens->validateToken($token);
    }

    private function findAndValidateUser(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !$this->verifyPassword($credentials['password'], $user)) {
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    private function verifyPassword(string $password, User $user): bool
    {
        return $this->encryption->verify(
            $password,
            $user->password_hash
        );
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (!$header || !preg_match('/Bearer\s+(.+)/', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
