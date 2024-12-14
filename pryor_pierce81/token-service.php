<?php

namespace App\Core\Auth;

class SecureTokenService
{
    private $encryptor;
    private $monitor;

    public function generateToken(array $claims): string
    {
        try {
            // Add security claims
            $claims = array_merge($claims, [
                'exp' => time() + 3600,
                'jti' => uniqid('token_'),
                'iat' => time()
            ]);

            // Generate secure token
            return $this->encryptor->encrypt(json_encode($claims));

        } catch (\Exception $e) {
            $this->monitor->tokenGenerationFailure($e);
            throw $e;
        }
    }

    public function validateToken(string $token): array
    {
        try {
            // Decrypt token
            $claims = json_decode(
                $this->encryptor->decrypt($token),
                true
            );

            // Validate expiration
            if ($claims['exp'] <= time()) {
                throw new TokenExpiredException();
            }

            return $claims;

        } catch (\Exception $e) {
            $this->monitor->tokenValidationFailure($e);
            throw $e;
        }
    }
}
