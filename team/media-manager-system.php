<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Storage\StorageManagerInterface;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\{DB, Cache};

class MediaManager implements MediaManagerInterface
{
    private SecurityManagerInterface $security;
    private StorageManagerInterface $storage;
    private ValidationService $validator;
    private ProcessingQueue $queue;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        StorageManagerInterface $storage,
        ValidationService $validator,
        ProcessingQueue $queue,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->queue = $queue;
        $this->config = $config;
    }

    public function upload(MediaFile $file, SecurityContext $context): MediaResource
    {
        return $this->security->executeCriticalOperation(
            function() use ($file, $context) {
                $this->validateFile($file);
                
                DB::beginTransaction();
                try {
                    $resource = $this->processUpload($file);
                    $this->queueProcessing($resource);
                    
                    DB::commit();
                    return $resource;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->cleanup($file);
                    throw $e;
                }
            },
            $context
        );
    }

    public function process(MediaResource $resource): void
    {
        $this->security->executeCriticalOperation(
            function() use ($resource) {
                $operations = $this->determineOperations($resource);
                
                foreach ($operations as $operation) {
                    $result = $this->executeOperation($resource, $operation);
                    $this->validateResult($result);
                    $this->updateResource($resource, $result);
                }
            },
            new SecurityContext('media.process')
        );
    }

    public function retrieve(string $id, array $options = []): MediaResource
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $options) {
                $resource = $this->findResource($id);
                
                if ($options['transform'] ?? false) {
                    $resource = $this->transformResource($resource, $options);
                }
                
                return $resource;
            },
            new SecurityContext('media.retrieve')
        );
    }

    protected function validateFile(MediaFile $file): void
    {
        if (!$this->validator->validateMediaFile($file)) {
            throw new MediaValidationException('Invalid media file');
        }

        if (!$this->validateMimeType($file)) {
            throw new MediaValidationException('Unsupported mime type');
        }

        if (!$this->validateFileSize($file)) {
            throw new MediaValidationException('File size exceeds limit');
        }

        $this->scanFile($file);
    }

    protected function processUpload(MediaFile $file): MediaResource
    {
        $path = $this->storage->store(
            $file,
            $this->generatePath($file)
        );

        return new MediaResource([
            'id' => $this->generateId(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $this->extractMetadata($file),
            'status' => MediaStatus::PENDING,
            'created_at' => time()
        ]);
    }

    protected function queueProcessing(MediaResource $resource): void
    {
        $operations = $this->determineOperations($resource);
        
        foreach ($operations as $operation) {
            $this->queue->enqueue(new ProcessingJob(
                $resource->getId(),
                $operation
            ));
        }
    }

    protected function executeOperation(MediaResource $resource, array $operation): array
    {
        $processor = $this->getProcessor($operation['type']);
        
        return $processor->process(
            $resource,
            $operation['config'] ?? []
        );
    }

    protected function validateResult(array $result): void
    {
        if (!isset($result['success']) || !$result['success']) {
            throw new MediaProcessingException(
                $result['error'] ?? 'Processing failed'
            );
        }
    }

    protected function updateResource(MediaResource $resource, array $result): void
    {
        $resource->update([
            'versions' => array_merge(
                $resource->getVersions(),
                [$result['version']]
            ),
            'metadata' => array_merge(
                $resource->getMetadata(),
                $result['metadata'] ?? []
            )
        ]);
    }

    protected function transformResource(MediaResource $resource, array $options): MediaResource
    {
        $transformer = $this->getTransformer($options['type']);
        
        return $transformer->transform(
            $resource,
            $options['config'] ?? []
        );
    }

    protected function validateMimeType(MediaFile $file): bool
    {
        return in_array(
            $file->getMimeType(),
            $this->config['allowed_types'] ?? []
        );
    }

    protected function validateFileSize(MediaFile $file): bool
    {
        return $file->getSize() <= ($this->config['max_size'] ?? 10485760);
    }

    protected function scanFile(MediaFile $file): void
    {
        if (!$this->security->scanFile($file->getPath())) {
            throw new SecurityException('File failed security scan');
        }
    }

    protected function generatePath(MediaFile $file): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            date('Y/m/d'),
            substr(md5(uniqid()), 0, 8),
            $file->getHash(),
            $file->getExtension()
        );
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function cleanup(MediaFile $file): void
    {
        try {
            unlink($file->getPath());
        } catch (\Throwable $e) {
            // Log but don't throw
        }
    }

    protected function getProcessor(string $type): ProcessorInterface
    {
        if (!isset($this->config['processors'][$type])) {
            throw new MediaConfigurationException("Unknown processor: $type");
        }

        return app($this->config['processors'][$type]);
    }

    protected function getTransformer(string $type): TransformerInterface
    {
        if (!isset($this->config['transformers'][$type])) {
            throw new MediaConfigurationException("Unknown transformer: $type");
        }

        return app($this->config['transformers'][$type]);
    }

    protected function extractMetadata(MediaFile $file): array
    {
        return array_filter([
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'hash' => $file->getHash(),
            'dimensions' => $this->getImageDimensions($file),
            'exif' => $this->getExifData($file)
        ]);
    }
}
