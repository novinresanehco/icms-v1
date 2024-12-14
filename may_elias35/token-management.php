```php
namespace App\Core\Security;

class TokenManager implements TokenInterface
{
    private EncryptionService $encryption;
    private CacheManager $cache;
    private SecurityConfig $config;

    public function generate(User $user, array $context = []): Token
    {
        // Generate cryptographically secure token
        $token = new Token([
            'user_id' => $user->id,
            'context' => $context,
            'expires' => now()->addMinutes($this->config->tokenExpiry),
            'fingerprint' => $this->generateFingerprint($context)
        ]);

        // Encrypt and store
        $token->signature = $this->encryption->sign($token->toArray());
        $this->cache->put($token->id, $token, $this->config->tokenExpiry);

        return $token;
    }

    public function verify(string $tokenString, array $options = []): bool
    {
        $token = $this->decrypt($tokenString);
        
        if (!$token || $token->isExpired()) {
            return false;
        }

        // Verify fingerprint for additional security
        if ($options['fingerprint'] ?? true) {
            return $this->verifyFingerprint($token);
        }

        return true;
    }

    private function generateFingerprint(array $context): string
    {
        return hash_hmac('sha256', json_encode([
            $context['ip'],
            $context['device'],
            session()->getId()
        ]), $this->config->appKey);
    }
}

class SecurityConfig
{
    public int $tokenExpiry = 15; // minutes
    public bool $requireDeviceVerification = true;
    public array $mfaMethods = ['totp', 'backup_codes'];
    public string $appKey;
    
    public function __construct()
    {
        $this->appKey = config('app.key');
    }
}
```
