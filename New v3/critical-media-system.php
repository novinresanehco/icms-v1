<?php

namespace App\Core\Media;

use App\Core\Protection\CoreProtectionSystem;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Http\UploadedFile;

class MediaManager implements MediaManagerInterface
{
    private CoreProtectionSystem $protection;
    private SecurityManager $security;
    private StorageManager $storage;
    private CacheManager $cache;
    private MediaValidator $validator;

    public function __construct(
        CoreProtectionSystem $protection,
        SecurityManager $security,
        StorageManager $storage,
        CacheManager $cache,
        MediaValidator $validator
    ) {
        $this->protection = $protection;
        $this->security = $security;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function store(UploadedFile $file, array $metadata = []): MediaFile
    {
        return $this->protection->executeProtectedOperation(
            fn() => $this->handleFileStorage($file, $metadata),
            ['type' => 'media_upload', 'file' => $file->getClientOriginalName()]
        );
    }

    private function handleFileStorage(UploadedFile $file, array $metadata): MediaFile
    {
        // Validate file
        $this->validator->validateFile($file);

        // Process and store the file
        $processedFile = $this->processFile($file);
        
        // Store with encryption
        $storagePath = $this->storage->store($processedFile);
        
        // Create database record
        $mediaFile = $this->createMediaRecord($storagePath, $metadata);
        
        // Generate thumbnails if image
        if ($this->isImage($file)) {
            $this->generateThumbnails($mediaFile);
        }

        return $mediaFile;
    }

    private function processFile(UploadedFile $file): ProcessedFile
    {
        $optimizer = new MediaOptimizer();
        return $optimizer->optimize($file);
    }

    private function createMediaRecord(string $path, array $metadata): MediaFile
    {
        return DB::transaction(function() use ($path, $metadata) {
            $mediaFile = new MediaFile([
                'path' => $this->security->encrypt($path),
                'metadata' => $this->security->encrypt(json_encode($metadata)),
                'hash' => $this->generateFileHash($path)
            ]);

            $mediaFile->save();
            $this->cache->invalidate(['media', $mediaFile->id]);
            
            return $mediaFile;
        });
    }

    public function retrieve(int $id): MediaFile
    {
        return $this->protection->executeProtectedOperation(
            fn() => $this->handleFileRetrieval($id),
            ['type' => 'media_retrieve', 'id' => $id]
        );
    }

    private function handleFileRetrieval(int $id): MediaFile
    {
        return $this->cache->remember(['media', $id], function() use ($id) {
            $mediaFile = MediaFile::findOrFail($id);
            
            // Verify file integrity
            $this->verifyFileIntegrity($mediaFile);
            
            // Decrypt path and metadata
            $mediaFile->path = $this->security->decrypt($mediaFile->path);
            $mediaFile->metadata = json_decode(
                $this->security->decrypt($mediaFile->metadata),
                true
            );
            
            return $mediaFile;
        });
    }

    private function verifyFileIntegrity(MediaFile $file): void
    {
        $currentHash = $this->generateFileHash($this->security->decrypt($file->path));
        if ($currentHash !== $file->hash) {
            throw new MediaIntegrityException('File integrity check failed');
        }
    }

    private function generateFileHash(string $path): string
    {
        return hash_file('sha256', $path);
    }

    private function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    private function generateThumbnails(MediaFile $file): void
    {
        $generator = new ThumbnailGenerator();
        $generator->generate($file);
    }
}

class MediaValidator
{
    private const MAX_FILE_SIZE = 10485760; // 10MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain'
    ];

    public function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaValidationException('Invalid file upload');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new MediaValidationException('File size exceeds limit');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new MediaValidationException('Unsupported file type');
        }

        // Scan file for malware
        $this->scanFile($file);
    }

    private function scanFile(UploadedFile $file): void
    {
        $scanner = new MalwareScanner();
        if (!$scanner->isClean($file)) {
            throw new SecurityException('File failed security scan');
        }
    }
}

class StorageManager
{
    private const ENCRYPTION_ALGO = 'aes-256-gcm';
    
    public function store(ProcessedFile $file): string
    {
        // Generate secure filename
        $filename = $this->generateSecureFilename($file);
        
        // Store file with encryption
        $encryptionKey = $this->generateEncryptionKey();
        $encryptedPath = $this->storeEncrypted($file, $filename, $encryptionKey);
        
        // Store encryption key securely
        $this->storeEncryptionKey($filename, $encryptionKey);
        
        return $encryptedPath;
    }

    private function generateSecureFilename(ProcessedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            bin2hex(random_bytes(16)),
            time(),
            $file->getExtension()
        );
    }

    private function storeEncrypted(
        ProcessedFile $file,
        string $filename,
        string $key
    ): string {
        $iv = random_bytes(openssl_cipher_iv_length(self::ENCRYPTION_ALGO));
        $ciphertext = openssl_encrypt(
            $file->getContents(),
            self::ENCRYPTION_ALGO,
            $key,
            0,
            $iv
        );
        
        $path = storage_path("media/{$filename}");
        file_put_contents($path, $iv . $ciphertext);
        
        return $path;
    }

    private function generateEncryptionKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function storeEncryptionKey(string $filename, string $key): void
    {
        // Store in secure key management system
    }
}
