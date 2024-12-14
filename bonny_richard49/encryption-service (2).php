<?php

namespace App\Core\Security;

use App\Core\Interfaces\EncryptionServiceInterface;
use App\Core\Exceptions\EncryptionException;
use Illuminate\Support\Facades\{Config, Log};

class EncryptionService implements EncryptionServiceInterface
{
    private string $cipher;
    private string $key;
    private array $config;
    private array $stats;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->cipher = 'aes-256-gcm';
        $this->key = $this->deriveKey();
        $this->stats = [
            'encryptions' => 0,
            'decryptions' => 0,
            'failures' => 0
        ];
    }

    public function encrypt(string $data): string
    {
        try {
            // Generate IV
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            
            // Generate AAD
            $aad = random_bytes(32);
            
            // Encrypt data
            $tag = '';
            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            // Combine IV, AAD, tag and encrypted data
            $combined = base64_encode(json_encode([
                'iv' => base64_encode($iv),
                'aad' => base64_encode($aad),
                'tag' => base64_encode($tag),
                'data' => base64_encode($encrypted),
                'version' => 1,
                'cipher' => $this->cipher
            ]));

            $this->stats['encryptions']++;
            
            return $combined;

        } catch (\Exception $e) {
            $this->handleError('encryption', $e);
            throw new EncryptionException('Encryption failed', 0, $e);
        }
    }

    public function decrypt(string $encryptedData): string
    {
        try {
            // Decode combined data
            $decoded = json_decode(base64_decode($encryptedData), true);
            if (!$this->validateEncryptedData($decoded)) {
                throw new EncryptionException('Invalid encrypted data format');
            }

            // Extract components
            $iv = base64_decode($decoded['iv']);
            $aad = base64_decode($decoded['aad']);
            $tag = base64_decode($decoded['tag']);
            $data = base64_decode($decoded['data']);

            // Verify cipher
            if ($decoded['cipher'] !== $this->cipher) {
                throw new EncryptionException('Unsupported cipher');
            }

            // Decrypt data
            $decrypted = openssl_decrypt(
                $data,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->stats['decryptions']++;
            
            return $decrypted;

        } catch (\Exception $e) {
            $this->handleError('decryption', $e);
            throw new EncryptionException('Decryption failed', 0, $e);
        }
    }

    public function encryptFile(string $inputPath, string $outputPath): bool
    {
        try {
            // Read file in chunks
            $handle = fopen($inputPath, 'rb');
            if ($handle === false) {
                throw new EncryptionException('Failed to open input file');
            }

            // Generate IV and AAD
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            $aad = random_bytes(32);
            $tag = '';

            // Create output file
            $output = fopen($outputPath, 'wb');
            if ($output === false) {
                fclose($handle);
                throw new EncryptionException('Failed to create output file');
            }

            // Write header
            $header = json_encode([
                'iv' => base64_encode($iv),
                'aad' => base64_encode($aad),
                'version' => 1,
                'cipher' => $this->cipher
            ]);
            fwrite($output, pack('N', strlen($header)));
            fwrite($output, $header);

            // Process file in chunks
            $chunkSize = 1024 * 1024; // 1MB chunks
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $encrypted = openssl_encrypt(
                    $chunk,
                    $this->cipher,
                    $this->key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    $aad
                );
                fwrite($output, $encrypted);
            }

            // Write tag at the end
            fwrite($output, $tag);

            fclose($handle);
            fclose($output);

            $this->stats['encryptions']++;
            
            return true;

        } catch (\Exception $e) {
            $this->handleError('file_encryption', $e);
            throw new EncryptionException('File encryption failed', 0, $e);
        }
    }

    public function decryptFile(string $inputPath, string $outputPath): bool
    {
        try {
            // Open input file
            $handle = fopen($inputPath, 'rb');
            if ($handle === false) {
                throw new EncryptionException('Failed to open encrypted file');
            }

            // Read header
            $headerLength = unpack('N', fread($handle, 4))[1];
            $header = json_decode(fread($handle, $headerLength), true);
            
            if (!$this->validateEncryptedHeader($header)) {
                throw new EncryptionException('Invalid encryption header');
            }

            // Extract encryption parameters
            $iv = base64_decode($header['iv']);
            $aad = base64_decode($header['aad']);

            // Create output file
            $output = fopen($outputPath, 'wb');
            if ($output === false) {
                fclose($handle);
                throw new EncryptionException('Failed to create output file');
            }

            // Process file in chunks
            $chunkSize = 1024 * 1024; // 1MB chunks
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $decrypted = openssl_decrypt(
                    $chunk,
                    $this->cipher,
                    $this->key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    '',
                    $aad
                );
                if ($decrypted === false) {
                    throw new EncryptionException('Decryption failed');
                }
                fwrite($output, $decrypted);
            }

            fclose($handle);
            fclose($output);

            $this->stats['decryptions']++;
            
            return true;

        } catch (\Exception $e) {
            $this->handleError('file_decryption', $e);
            throw new EncryptionException('File decryption failed', 0, $e);
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    protected function deriveKey(): string
    {
        $salt = $this->config['key_salt'] ?? Config::get('app.key');
        $iterations = $this->config['key_iterations'] ?? 10000;
        
        return hash_pbkdf2(
            'sha256',
            Config::get('app.key'),
            $salt,
            $iterations,
            32,
            true
        );
    }

    protected function validateEncryptedData(array $data): bool
    {
        $required = ['iv', 'aad', 'tag', 'data', 'version', 'cipher'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }
        return true;
    }

    protected function validateEncryptedHeader(array $header): bool
    {
        $required = ['iv', 'aad', 'version', 'cipher'];
        foreach ($required as $field) {
            if (!isset($header[$field])) {
                return false;
            }
        }
        return true;
    }

    protected function handleError(string $operation, \Exception $e): void
    {
        $this->stats['failures']++;
        
        Log::error("Encryption operation failed: $operation", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
