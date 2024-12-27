<?php 

namespace App\Security;

class SecurityService implements SecurityInterface
{
    private EncryptionManager $encryption;
    private HashManager $hash;
    private TokenManager $tokens;
    private LoggerService $logger;

    public function validateRequest(Request $request): bool
    {
        $token = $this->tokens->validate($request->bearerToken());
        $this->logger->security('token.validated', $token);
        return true;
    }

    public function encrypt(array $data): string
    {
        $json = json_encode($data);
        return $this->encryption->encrypt($json);
    }

    public function decrypt(string $encrypted): array
    {
        $json = $this->encryption->decrypt($encrypted);
        return json_decode($json, true);
    }

    public function hash(string $value): string
    {
        return $this->hash->make($value);
    }
}

class EncryptionManager implements EncryptionInterface
{
    private string $key;
    private string $cipher;

    public function encrypt(string $value): string
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $encrypted = openssl_encrypt($value, $this->cipher, $this->key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, openssl_cipher_iv_length($this->cipher));
        $encrypted = substr($data, openssl_cipher_iv_length($this->cipher));
        return openssl_decrypt($encrypted, $this->cipher, $this->key, 0, $iv);
    }
}

class HashManager implements HashInterface
{
    private int $rounds = 12;

    public function make(string $value): string
    {
        return password_hash($value, PASSWORD_BCRYPT, [
            'cost' => $this->rounds
        ]);
    }

    public function verify(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }
}

class TokenManager implements TokenInterface
{
    private string $key;
    private int $lifetime;

    public function generate(array $claims): string
    {
        $now = time();
        $token = [
            'iat' => $now,
            'exp' => $now + $this->lifetime,
            'claims' => $claims
        ];
        
        return JWT::encode($token, $this->key);
    }

    public function validate(string $token): array
    {
        try {
            return JWT::decode($token, $this->key);
        } catch (\Exception $e) {
            throw new TokenException('Invalid token');
        }
    }
}
