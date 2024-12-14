<?php

namespace App\Core\Storage;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class StorageManager implements StorageInterface
{
    private ValidationService $validator;
    private AuditLogger $audit;
    private array $config;

    private const MAX_FILE_SIZE = 104857600; // 100MB
    private const RETRY_ATTEMPTS = 3;
    private const CHUNK_SIZE = 8192; // 8KB

    public function __construct(
        ValidationService $validator,
        AuditLogger $audit,
        array $config
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function store(
        string $path,
        mixed $data,
        SecurityContext $context,
        array $metadata = []
    ): StorageResult {
        DB::beginTransaction();

        try {
            // Validate request
            $this->validateStoreRequest($path, $data, $context);
            
            // Prepare data
            $preparedData = $this->prepareForStorage($data, $metadata);
            
            // Store data with retry mechanism
            $result = $this->storeWithRetry($path, $preparedData);
            
            // Store metadata
            $this->storeMetadata($path, $metadata, $context);
            
            DB::commit();
            
            // Log operation
            $this->audit->logStorageOperation('store', $path, $context);
            
            return new StorageResult($result);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleStorageFailure($e, 'store', $path);
            throw $e;
        }
    }

    public function retrieve(
        string $path,
        SecurityContext $context
    ): mixed {
        try {
            // Validate request
            $this->validateRetrieveRequest($path, $context);
            
            // Check metadata and permissions
            $metadata = $this->validateAccess($path, $context);
            
            // Retrieve data
            $data = $this->retrieveWithRetry($path);
            
            // Verify data integrity
            $this->verifyDataIntegrity($data, $metadata);
            
            // Log access
            $this->audit->logStorageAccess($path, $context);
            
            return $data;
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'retrieve', $path);
            throw $e;
        }
    }

    public function delete(
        string $path,
        SecurityContext $context
    ): void {
        DB::beginTransaction();

        try {
            // Validate request
            $this->validateDeleteRequest($path, $context);
            
            // Check permissions
            $this->validateAccess($path, $context);
            
            // Create backup
            $this->createBackup($path);
            
            // Delete data
            $this->deleteWithRetry($path);
            
            // Remove metadata
            $this->deleteMetadata($path);
            
            DB::commit();
            
            // Log deletion
            $this->audit->logStorageOperation('delete', $path, $context);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleStorageFailure($e, 'delete', $path);
            throw $e;
        }
    }

    private function validateStoreRequest(string $path, mixed $data, SecurityContext $context): void
    {
        if (!$this->validator->validatePath($path)) {
            throw new StorageValidationException('Invalid storage path');
        }

        if (!$this->validator->validateStorageData($data)) {
            throw new StorageValidationException('Invalid storage data');
        }

        if (!$context->canWrite($path)) {
            throw new StorageSecurityException('Write access denied');
        }

        if ($this->getDataSize($data) > self::MAX_FILE_SIZE) {
            throw new StorageValidationException('Data size exceeds limit');
        }
    }

    private function validateRetrieveRequest(string $path, SecurityContext $context): void
    {
        if (!$this->validator->validatePath($path)) {
            throw new StorageValidationException('Invalid storage path');
        }

        if (!$context->canRead($path)) {
            throw new StorageSecurityException('Read access denied');
        }
    }

    private function validateDeleteRequest(string $path, SecurityContext $context): void
    {
        if (!$this->validator->validatePath($path)) {
            throw new StorageValidationException('Invalid storage path');
        }

        if (!$context->canDelete($path)) {
            throw new StorageSecurityException('Delete access denied');
        }
    }

    private function prepareForStorage(mixed $data, array $metadata): array
    {
        return [
            'data' => $data,
            'metadata' => array_merge($metadata, [
                'created_at' => now(),
                'checksum' => $this->generateChecksum($data),
                'size' => $this->getDataSize($data)
            ])
        ];
    }

    private function storeWithRetry(string $path, array $data): bool
    {
        $attempts = 0;
        
        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                if (is_resource($data['data'])) {
                    return $this->storeStream($path, $data);
                }
                
                return Storage::put($path, serialize($data));
                
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::RETRY_ATTEMPTS) {
                    throw new StorageOperationException(
                        'Storage operation failed after retries',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }
        
        throw new StorageOperationException('Storage operation failed');
    }

    private function storeStream(string $path, array $data): bool
    {
        $stream = Storage::getDriver()->writeStream($path);
        
        while (!feof($data['data'])) {
            $chunk = fread($data['data'], self::CHUNK_SIZE);
            if ($chunk === false || fwrite($stream, $chunk) === false) {
                throw new StorageOperationException('Failed to write stream');
            }
        }
        
        return fclose($stream);
    }

    private function retrieveWithRetry(string $path): mixed
    {
        $attempts = 0;
        
        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                $data = Storage::get($path);
                return unserialize($data);
                
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::RETRY_ATTEMPTS) {
                    throw new StorageOperationException(
                        'Retrieve operation failed after retries',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }
        
        throw new StorageOperationException('Retrieve operation failed');
    }

    private function deleteWithRetry(string $path): bool
    {
        $attempts = 0;
        
        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                return Storage::delete($path);
                
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::RETRY_ATTEMPTS) {
                    throw new StorageOperationException(
                        'Delete operation failed after retries',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }
        
        throw new StorageOperationException('Delete