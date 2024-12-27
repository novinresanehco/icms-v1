<?php

namespace App\Core\Security;

class TokenManager implements TokenManagerInterface
{
    private EncryptionService $encryption;
    private string $secret;
    private int $expiration;

    public function __construct(EncryptionService $encryption, string $secret, int $expiration)
    {
        $this->encryption = $encryption;
        $this->secret = $secret;
        $this->expiration = $expiration;
    }

    public function generate(): string
    {
        $payload = [
            'id' => bin2hex(random_bytes(16)),
            'exp' => time() + $this->expiration,
            'iat' => time()
        ];

        return $this->createToken($payload);
    }

    public function validate(string $token): bool
    {
        try {
            $payload = $this->decodeToken($token);
            return $payload['exp'] > time();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function decode(string $token): array
    {
        return $this->decodeToken($token);
    }

    private function createToken(array $payload): string
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode($payload));
        $signature = $this->encryption->hash($header . '.' . $payload);
        
        return $header . '.' . $payload . '.' . $signature;
    }

    private function decodeToken(string $token): array
    {
        [$header, $payload, $signature] = explode('.', $token);
        
        if (!$this->encryption->verify($header . '.' . $payload, $signature)) {
            throw new SecurityException('Invalid token signature');
        }

        return json_decode(base64_decode($payload), true);
    }
}
