<?php

namespace App\Core\Media;

class MediaManager implements MediaManagerInterface 
{
    private SecurityManager $security;
    private MediaStorage $storage;
    private MediaProcessor $processor;
    private CacheManager $cache;
    private ValidationService $validator;

    public function upload(UploadedFile $file): MediaResult 
    {
        return $this->security->executeCriticalOperation(
            new UploadMediaOperation(
                $file,
                $this->storage,
                $this->processor,
                $this->validator
            )
        );
    }

    public function process(Media $media, array $operations): MediaResult 
    {
        return $this->security->executeCriticalOperation(
            new ProcessMediaOperation(
                $media,
                $operations,
                $this->processor,
                $this->validator
            )
        );
    }

    public function getMedia(int $id): ?Media 
    {
        return $this->cache->remember(
            "media.$id",
            fn() => $this->storage->find($id)
        );
    }
}

class MediaStorage implements StorageInterface 
{
    private DB $database;
    private FileSystem $filesystem;
    private EncryptionService $encryption;

    public function store(UploadedFile $file, array $metadata): Media 
    {
        return DB::transaction(function() use ($file, $metadata) {
            $path = $this->filesystem->store(
                $file,
                $this->generateSecurePath()
            );

            $media = $this->database->table('media')->create([
                'path' => $this->encryption->encrypt($path),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => json_encode($metadata),
                'checksum' => hash_file('sha256', $file->getPathname())
            ]);

            return $media;
        });
    }

    public function delete(int $id): bool 
    {
        return DB::transaction(function() use ($id) {
            $media = $this->find($id);
            if (!$media) return false;

            $path = $this->encryption->decrypt($media->path);
            $this->filesystem->delete($path);
            
            return $this->database->table('media')->delete($id);
        });
    }

    private function generateSecurePath(): string 
    {
        return sprintf(
            '%s/%s/%s',
            date('Y/m'),
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(16))
        );
    }
}

class MediaProcessor implements ProcessorInterface 
{
    private ImageProcessor $imageProcessor;
    private VideoProcessor $videoProcessor;
    private SecurityManager $security;

    public function process(Media $media, array $operations): ProcessingResult 
    {
        $processor = $this->getProcessor($media->mime_type);
        
        return $processor->process($media, array_map(
            fn($op) => $this->validateOperation($op),
            $operations
        ));
    }

    private function validateOperation(array $operation): array 
    {
        if (!isset($operation['type']) || !isset($operation['params'])) {
            throw new ValidationException('Invalid operation format');
        }

        if (!$this->isOperationAllowed($operation['type'])) {
            throw new SecurityException('Operation not allowed');
        }

        return $operation;
    }

    private function getProcessor(string $mimeType): ProcessorInterface 
    {
        return match(explode('/', $mimeType)[0]) {
            'image' => $this->imageProcessor,
            'video' => $this->videoProcessor,
            default => throw new UnsupportedMediaTypeException()
        };
    }
}

class UploadMediaOperation implements CriticalOperation 
{
    private UploadedFile $file;
    private MediaStorage $storage;
    private MediaProcessor $processor;
    private ValidationService $validator;

    public function __construct(
        UploadedFile $file,
        MediaStorage $storage,
        MediaProcessor $processor,
        ValidationService $validator
    ) {
        $this->file = $file;
        $this->storage = $storage;
        $this->processor = $processor;
        $this->validator = $validator;
    }

    public function execute(): MediaResult 
    {
        $this->validateFile($this->file);

        $media = $this->storage->store($this->file, [
            'original_name' => $this->file->getClientOriginalName(),
            'uploaded_at' => now()
        ]);

        return new MediaResult($media);
    }

    private function validateFile(UploadedFile $file): void 
    {
        if (!$file->isValid()) {
            throw new UploadException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), $this->getAllowedMimeTypes())) {
            throw new SecurityException('File type not allowed');
        }

        if ($file->getSize() > config('media.max_size')) {
            throw new ValidationException('File size exceeds limit');
        }
    }

    public function getRequiredPermissions(): array 
    {
        return ['media.upload'];
    }
}

class MediaResult 
{
    private Media $media;
    private array $meta;

    public function __construct(Media $media, array $meta = []) 
    {
        $this->media = $media;
        $this->meta = $meta;
    }

    public function getMedia(): Media 
    {
        return $this->media;
    }

    public function isValid(): bool 
    {
        return $this->media->exists;
    }
}
