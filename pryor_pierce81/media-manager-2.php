<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\MediaException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MediaManager implements MediaManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function store(UploadedFile $file, array $options = []): MediaFile
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('media:store', [
                'operation_id' => $operationId
            ]);

            $this->validateFile($file);
            $mediaFile = $this->processAndStoreFile($file, $options);
            
            $this->logMediaOperation($operationId, 'store', $mediaFile->getId());

            DB::commit();
            return $mediaFile;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMediaFailure($operationId, 'store', $e);
            throw new MediaException('Media storage failed', 0, $e);
        }
    }

    public function retrieve(string $id): MediaFile
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('media:retrieve', [
                'operation_id' => $operationId,
                'media_id' => $id
            ]);

            $mediaFile = $this->findMediaFile($id);
            $this->logMediaOperation($operationId, 'retrieve', $id);
            
            return $mediaFile;

        } catch (\Exception $e) {
            $this->handleMediaFailure($operationId, 'retrieve', $e);
            throw new MediaException('Media retrieval failed', 0, $e);
        }
    }

    public function delete(string $id): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('media:delete', [
                'operation_id' => $operationId,
                'media_id' => $id
            ]);

            $mediaFile = $this->findMediaFile($id);
            $this->deleteMediaFile($mediaFile);
            
            $this->logMediaOperation($operationId, 'delete', $id);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMediaFailure($operationId, 'delete', $e);
            throw new MediaException('Media deletion failed', 0, $e);
        }
    }

    public function process(string $id, array $operations): MediaFile
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('media:process', [
                'operation_id' => $operationId,
                'media_id' => $id
            ]);

            $mediaFile = $this->findMediaFile($id);
            $processedFile = $this->executeOperations($mediaFile, $operations);
            
            $this->logMediaOperation($operationId, 'process', $id);

            DB::commit();
            return $processedFile;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMediaFailure($operationId, 'process', $e);
            throw new MediaException('Media processing failed', 0, $e);
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File size exceeds limit');
        }

        if (!in_array($file->getMimeType(), $this->config['allowed_mime_types'])) {
            throw new MediaException('File type not allowed');
        }

        if (!$this->validateFileContent($file)) {
            throw new MediaException('File content validation failed');
        }
    }

    private function processAndStoreFile(UploadedFile $file, array $options): MediaFile
    {
        $fileName = $this->generateFileName($file);
        $path = $this->storeFileSecurely($file, $fileName);
        
        $mediaFile = new MediaFile([
            'id' => $this->generateFileId(),
            'name' => $fileName,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $this->extractMetadata($file),
            'security_hash' => $this->generateSecurityHash($file)
        ]);

        $this->saveMediaRecord($mediaFile);
        return $mediaFile;
    }

    private function validateFileContent(UploadedFile $file): bool
    {
        // Implement file content validation based on mime type
        return true;
    }

    private function storeFileSecurely(UploadedFile $file, string $fileName): string
    {
        $directory = $this->getSecureDirectory();
        return Storage::putFileAs($directory, $file, $fileName, 'private');
    }

    private function deleteMediaFile(MediaFile $mediaFile): void
    {
        Storage::delete($mediaFile->getPath());
        
        DB::table('media_files')
            ->where('id', $mediaFile->getId())
            ->delete();
    }

    private function executeOperations(MediaFile $mediaFile, array $operations): MediaFile
    {
        $processedFile = clone $mediaFile;

        foreach ($operations as $operation) {
            $processedFile = $this->executeOperation($processedFile, $operation);
        }

        $this->saveMediaRecord($processedFile);
        return $processedFile;
    }

    private function executeOperation(MediaFile $mediaFile, array $operation): MediaFile
    {
        switch ($operation['type']) {
            case 'resize':
                return $this->resizeImage($mediaFile, $operation);
            case 'compress':
                return $this->compressFile($mediaFile, $operation);
            case 'convert':
                return $this->convertFormat($mediaFile, $operation);
            default:
                throw new MediaException('Unsupported operation type');
        }
    }

    private function generateFileName(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid('file_', true),
            hash('xxh3', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }

    private function generateFileId(): string
    {
        return uniqid('media_', true);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function generateSecurityHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    private function getSecureDirectory(): string
    {
        $directory = 'media/' . date('Y/m/d');
        Storage::makeDirectory($directory);
        return $directory;
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_file_size' => 10 * 1024 * 1024,
            'allowed_mime_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'application/pdf',
                'text/plain'
            ],
            'image_max_dimensions' => [
                'width' => 4096,
                'height' => 4096
            ],
            'compression_quality' => 85,
            'secure_storage' => true
        ];
    }

    private function handleMediaFailure(string $operationId, string $operation, \Exception $e): void
    {
        $this->logger->error('Media operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
