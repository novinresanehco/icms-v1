<?php

namespace App\Core\Security;

use App\Core\Encryption\EncryptionService;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;

class TokenManager implements TokenInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $audit;
    private array $config;

    private const TOKEN_VERSION = 'v2';
    private const ENCRYPTION_ALGO = 'aes-256-gcm';
    private const HASH_ALGO = 'sha256';

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $audit,
        array $config
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function generateToken(array $data): string
    {
        try {
            // Validate token data
            $this->validateTokenData($data);
            
            // Generate token content
            $tokenContent = $this->generateTokenContent($data);
            
            // Encrypt token
            $encryptedToken = $this->encryptToken($tokenContent);
            
            // Create final token
            $token = $this->createFinalToken($encryptedToken);
            
            // Store token metadata
            $this->storeTokenMetadata($token, $data);
            
            return $token;
            
        } catch (\Exception $e) {
            $this->handleTokenGenerationFailure($e, $data);
            throw $e;
        }
    }

    public function verifyToken(string $token): array
    {
        try {
            // Parse token
            $parsedToken = $this->parseToken($token);
            
            // Verify token integrity
            $this->verifyTokenIntegrity($parsedToken);
            
            // Decrypt token
            $decryptedData = $this->decryptToken($parsedToken);
            
            // Validate token data
            $this->validateTokenData($decryptedData);
            
            // Verify token metadata
            $this->verifyTokenMetadata($token, $decryptedData);
            
            return $decryptedData;
            
        } catch (\Exception $e) {
            $this->handleTokenVerificationFailure($e, $token);
            throw $e;
        }
    }

    public function revokeToken(string $token): void
    {
        DB::beginTransaction();
        
        try {
            // Verify token before revocation
            $this->verifyToken($token);
            
            // Add to revocation list
            $this->addToRevocationList($token);
            
            // Remove token metadata
            $this->removeTokenMetadata($token);
            
            DB::commit();
            
            // Log revocation
            $this->audit->logTokenRevocation($token);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTokenRevocationFailure($e, $token);
            throw $e;
        }
    }

    private function validateTokenData(array $data): void
    {
        if (!$this->validator->validateTokenData($data)) {
            throw new TokenException('Invalid token data');
        }
    }

    private function generateTokenContent(array $data): array
    {
        return [
            'version' => self::TOKEN_VERSION,
            'data' => $data,
            'created_at' => now()->timestamp,
            'nonce' => $this->generateNonce()
        ];
    }

    private function encryptToken(array $content): string
    {
        return $this->encryption->encrypt(
            json_encode($content),
            self::ENCRYPTION_ALGO
        );
    }

    private function createFinalToken(string $encrypted): string
    {
        $signature = $this->generateSignature($encrypted);
        return base64_encode($encrypted . '.' . $signature);
    }

    private function parseToken(string $token): array
    {
        $decoded = base64_decode($token);
        
        if ($decoded === false) {
            throw new TokenException('Invalid token format');
        }
        
        [$encrypted, $signature] = explode('.', $decoded);
        
        if (!$this->verifySignature($encrypted, $signature)) {
            throw new TokenException('Invalid token signature');
        }
        
        return ['encrypted' => $encrypted, 'signature' => $signature];
    }

    private function verifyTokenIntegrity(array $parsedToken): void
    {
        if (!isset($parsedToken['encrypted'], $parsedToken['signature'])) {
            throw new TokenException('Incomplete token structure');
        }
    }

    private function decryptToken(array $parsedToken): array
    {
        $decrypted = $this->encryption->decrypt(
            $parsedToken['encrypted'],
            self::ENCRYPTION_ALGO
        );
        
        return json_decode($decrypted, true);
    }

    private function generateSignature(string $data): string
    {
        return hash_hmac(self::HASH_ALGO, $data, $this->config['secret_key']);
    }

    private function verifySignature(string $data, string $signature): bool
    {
        return hash_equals(
            $this->generateSignature($data),
            $signature
        );
    }

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function handleTokenGenerationFailure(\Exception $e, array $data): void
    {
        $this->audit->logTokenGenerationFailure([
            'error' => $e->getMessage(),
            'data' => $data,
            'timestamp' => now()
        ]);
    }
}
