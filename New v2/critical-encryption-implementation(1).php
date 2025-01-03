<?php

namespace App\Core\Encryption;

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;
    private KeyManager $keys;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function encrypt($data, array $options = []): string
    {
        $monitorId = $this->metrics->startOperation('encryption');
        
        try {
            // Generate IV
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            
            // Get encryption key
            $key = $this->getEncryptionKey($options);
            
            // Encrypt data
            $encrypted = openssl_encrypt(
                serialize($data),
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            // Add authentication tag
            $tag = $this->generateTag($encrypted, $iv, $key);
            
            // Combine for storage
            $result = base64_encode($iv . $tag . $encrypted);
            
            $this->metrics->recordSuccess($monitorId);
            return $result;
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            throw new EncryptionException('Encryption failed', 0, $e);
        }
    }

    public function decrypt(string $data, array $options = []): mixed
    {
        $monitorId = $this->metrics->startOperation('decryption');
        
        try {
            // Decode from storage format
            $data = base64_decode($data);
            
            // Extract components
            $iv = substr($data, 0, openssl_cipher_iv_length($this->cipher));
            $tag = substr($data, openssl_cipher_iv_length($this->cipher), 32);
            $encrypted = substr($data, openssl_cipher_iv_length($this->cipher) + 32);
            
            // Get decryption key
            $key = $this->getEncryptionKey($options);
            
            // Verify authentication tag
            if (!$this->verifyTag($