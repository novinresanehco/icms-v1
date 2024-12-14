<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{DB, Storage, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    ProcessingService,
    CacheManager,
    AuditLogger
};
use App\Core\Exceptions\{
    MediaException,
    SecurityException,
    ValidationException
};

class MediaManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ProcessingService $processor;
    private CacheManager $cache;
    private AuditLogger $auditLogger;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ProcessingService $processor,
        CacheManager $cache,
        AuditLogger $auditLogger,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function handleMediaUpload(array $fileData, array $metadata = []): array
    {
        return $this->security->executeCriticalOperation(function() use ($fileData, $metadata) {
            // Validate file and metadata
            $this->validateUpload($fileData, $metadata);
            
            return DB::transaction(function() use ($fileData, $metadata) {
                // Process and store file
                $processedFile = $this->processMediaFile($fileData);
                $storedFile = $this->storeMediaFile($processedFile);
                
                // Create media record
                $mediaRecord = $this->createMediaRecord($storedFile, $metadata);
                
                // Generate derivatives if needed
                if ($this->requiresDerivatives($storedFile)) {
                    $this->generateDerivatives($mediaRecord);
                }
                
                // Log media creation
                $this->auditLogger->logMediaCreation([
                    'media_id' => $mediaRecord['id'],
                    'type' => $storedFile['type'],
                    'size' => $storedFile['size'],
                    'user_id' => auth()->id()
                ]);
                
                return $mediaRecord;
            });
        }, ['operation' => 'media_upload']);
    }

    public function processMediaFile(array $mediaRecord): array
    {
        return $this->security->executeCriticalOperation(function() use ($mediaRecord) {
            $this->validateMediaRecord($mediaRecord);
            
            $processedFile = $this->processor->processFile(
                $mediaRecord['path'],
                $this->getProcessingConfig($mediaRecord)
            );
            
            $this->validateProcessedFile($processedFile);
            
            return $processedFile;
        }, ['operation' => 'media_process']);
    }

    public function getSecureUrl(int $mediaId, array $options = []): string
    {
        $mediaRecord = $this->getMediaRecord($mediaId);
        $this->validateAccess($mediaRecord);

        if ($this->usesCaching($mediaRecord)) {
            return $this->getCachedUrl($mediaRecord, $options);
        }

        return $this->generateSecureUrl($mediaRecord, $options);
    }

    protected function validateUpload(array $fileData, array $metadata): void
    {
        if (!$this->validator->validateMediaFile($fileData)) {
            throw new ValidationException('Invalid media file');
        }

        if (!$this->validator->validateMediaMetadata($metadata)) {
            throw new ValidationException('Invalid media metadata');
        }

        $this->validateStorageCapacity($fileData['size']);
    }

    protected function processMediaFile(array $fileData): array
    {
        $config = $this->getProcessingConfig([
            'type' => $fileData['type'],
            'size' => $fileData['size']
        ]);

        return $this->processor->processFile($fileData['tmp_name'], $config);
    }

    protected function storeMediaFile(array $processedFile): array
    {
        $path = $this->generateSecurePath($processedFile);
        
        Storage::put(
            $path,
            file_get_contents($processedFile['path']),
            ['visibility' => 'private']
        );

        return array_merge($processedFile, ['path' => $path]);
    }

    protected function createMediaRecord(array $fileData, array $metadata): array
    {
        $record = [
            'path' => $fileData['path'],
            'type' => $fileData['type'],
            'size' => $fileData['size'],
            'metadata' => json_encode($metadata),
            'checksum' => hash_file('sha256', $fileData['path']),
            'created_by' => auth()->id(),
            'created_at' => now()
        ];

        $id = DB::table('media')->insertGetId($record);
        return array_merge($record, ['id' => $id]);
    }

    protected function generateDerivatives(array $mediaRecord): void
    {
        $derivatives = $this->processor->generateDerivatives(
            $mediaRecord['path'],
            $this->getDerivativeConfig($mediaRecord)
        );

        foreach ($derivatives as $derivative) {
            $this->storeDerivative($mediaRecord['id'], $derivative);
        }
    }

    protected function validateMediaRecord(array $mediaRecord): void
    {
        if (!$this->validator->validateMediaRecord($mediaRecord)) {
            throw new ValidationException('Invalid media record');
        }
    }

    protected function validateProcessedFile(array $processedFile): void
    {
        if (!$this->validator->validateProcessedMedia($processedFile)) {
            throw new ValidationException('Media processing failed validation');
        }
    }

    protected function validateStorageCapacity(int $fileSize): void
    {
        if (!$this->hasStorageCapacity($fileSize)) {
            throw new MediaException('Insufficient storage capacity');
        }
    }

    protected function validateAccess(array $mediaRecord): void
    {
        if (!$this->security->validateAccess($mediaRecord, 'view')) {
            throw new SecurityException('Media access denied');
        }
    }

    protected function getCachedUrl(array $mediaRecord, array $options): string
    {
        $cacheKey = $this->generateUrlCacheKey($mediaRecord, $options);
        
        return Cache::remember(
            $cacheKey,
            $this->config['url_cache_ttl'],
            fn() => $this->generateSecureUrl($mediaRecord, $options)
        );
    }

    protected function generateSecureUrl(array $mediaRecord, array $options): string
    {
        return Storage::temporaryUrl(
            $mediaRecord['path'],
            now()->addMinutes($this->config['url_expiry']),
            $this->getUrlOptions($options)
        );
    }

    private function generateSecurePath(array $fileData): string
    {
        return sprintf(
            '%s/%s/%s',
            date('Y/m'),
            hash('sha256', uniqid('', true)),
            $this->sanitizeFileName($fileData['name'])
        );
    }

    private function sanitizeFileName(string $fileName): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $fileName);
    }

    private function hasStorageCapacity(int $fileSize): bool
    {
        $currentUsage = Cache::remember('storage_usage', 3600, function() {
            return Storage::size($this->config['storage_path']);
        });

        return ($currentUsage + $fileSize) <= $this->config['storage_limit'];
    }
}
