<?php

namespace App\Core\Media;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\{ValidationService, StorageService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\{MediaException, SecurityException};

class MediaManager implements MediaManagerInterface 
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private StorageService $storage;
    private array $allowedTypes;
    private array $maxSizes;
    private array $processors;
    
    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        StorageService $storage,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->allowedTypes = $config['allowed_types'] ?? [];
        $this->maxSizes = $config['max_sizes'] ?? [];
        $this->processors = $config['processors'] ?? [];
    }

    public function uploadMedia(UploadedFile $file, array $context): MediaFile 
    {
        return $this->security->executeSecureOperation(
            function() use ($file, $context) {
                $this->validateUpload($file);
                
                return DB::transaction(function() use ($file, $context) {
                    $processedFile = $this->processFile($file);
                    $mediaFile = $this->createMediaRecord($processedFile, $context);
                    $this->generateDerivatives($mediaFile);
                    return $mediaFile;
                });
            },
            array_merge($context, ['action' => 'upload'])
        );
    }

    public function deleteMedia(int $id, array $context): bool 
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                return DB::transaction(function() use ($id) {
                    $media = $this->findMedia($id);
                    $this->storage->deleteFiles($media->getAllPaths());
                    return $media->delete();
                });
            },
            array_merge($context, ['action' => 'delete'])
        );
    }

    public function processMedia(int $id, array $operations, array $context): MediaFile 
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $operations, $context) {
                return DB::transaction(function() use ($id, $operations) {
                    $media = $this->findMedia($id);
                    foreach ($operations as $operation) {
                        $this->processOperation($media, $operation);
                    }
                    return $media->fresh();
                });
            },
            array_merge($context, ['action' => 'process'])
        );
    }

    protected function validateUpload(UploadedFile $file): void 
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }

        $type = $file->getMimeType();
        if (!in_array($type, $this->allowedTypes)) {
            throw new SecurityException("File type not allowed: $type");
        }

        $size = $file->getSize();
        $maxSize = $this->maxSizes[$type] ?? 0;
        if ($maxSize && $size > $maxSize) {
            throw new MediaException("File size exceeds limit: $size > $maxSize");
        }
    }

    protected function processFile(UploadedFile $file): ProcessedFile 
    {
        $type = $file->getMimeType();
        $processor = $this->getProcessor($type);
        
        try {
            return $processor->process($file);
        } catch (\Exception $e) {
            throw new MediaException('File processing failed: ' . $e->getMessage());
        }
    }

    protected function createMediaRecord(ProcessedFile $file, array $context): MediaFile 
    {
        $path = $this->storage->storeFile($file);
        
        return MediaFile::create([
            'path' => $path,
            'filename' => $file->getOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $this->extractMetadata($file),
            'user_id' => $context['user_id'],
            'created_at' => now()
        ]);
    }

    protected function generateDerivatives(MediaFile $media): void 
    {
        $type = $media->mime_type;
        if (isset($this->processors[$type])) {
            $processor = $this->processors[$type];
            $derivatives = $processor->generateDerivatives($media->path);
            
            foreach ($derivatives as $derivative) {
                $this->storage->storeDerivative($media->id, $derivative);
            }
            
            $media->update(['has_derivatives' => true]);
        }
    }

    protected function processOperation(MediaFile $media, array $operation): void 
    {
        $type = $operation['type'] ?? '';
        $params = $operation['params'] ?? [];
        
        if (!$this->validator->validateOperation($type, $params)) {
            throw new MediaException('Invalid operation parameters');
        }
        
        $processor = $this->getProcessor($media->mime_type);
        $result = $processor->processOperation($media->path, $type, $params);
        
        $this->storage->storeProcessedFile($media->id, $result);
        $media->addProcessingHistory($operation);
    }

    protected function findMedia(int $id): MediaFile 
    {
        $media = MediaFile::find($id);
        if (!$media) {
            throw new MediaException("Media file not found: $id");
        }
        return $media;
    }

    protected function getProcessor(string $mimeType): MediaProcessor 
    {
        if (!isset($this->processors[$mimeType])) {
            throw new MediaException("No processor for type: $mimeType");
        }
        return $this->processors[$mimeType];
    }

    protected function extractMetadata(ProcessedFile $file): array 
    {
        try {
            return [
                'dimensions' => $file->getDimensions(),
                'exif' => $file->getExifData(),
                'hash' => $file->getHash(),
                'processed_at' => now()->toDateTimeString()
            ];
        } catch (\Exception $e) {
            return ['error' => 'Metadata extraction failed'];
        }
    }
}
