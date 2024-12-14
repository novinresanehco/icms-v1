<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Storage\StorageManager;
use App\Core\Exceptions\{MediaException, ValidationException};
use Illuminate\Support\Facades\DB;

class MediaManager implements MediaManagerInterface 
{
    private SecurityManager $security;
    private StorageManager $storage;
    private CacheManager $cache;
    private MediaRepository $repository;
    private FileProcessor $processor;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        StorageManager $storage,
        CacheManager $cache,
        MediaRepository $repository,
        FileProcessor $processor,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->processor = $processor;
        $this->logger = $logger;
    }

    public function uploadMedia(UploadedFile $file, array $metadata, SecurityContext $context): MediaEntity 
    {
        return $this->security->executeCriticalOperation(
            new UploadMediaOperation(
                $file,
                $metadata,
                $this->storage,
                $this->processor,
                $this->repository
            ),
            $context
        );
    }

    public function getMedia(int $id, SecurityContext $context): MediaEntity 
    {
        return $this->cache->remember(
            "media.{$id}",
            fn() => $this->repository->findWithSecurity($id, $context)
        );
    }

    public function deleteMedia(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteMediaOperation($id, $this->storage, $this->repository),
            $context
        );
    }

    public function processMediaVariant(
        int $id,
        string $variant,
        array $options,
        SecurityContext $context
    ): MediaVariant {
        return $this->security->executeCriticalOperation(
            new ProcessMediaVariantOperation(
                $id,
                $variant,
                $options,
                $this->storage,
                $this->processor,
                $this->repository
            ),
            $context
        );
    }
}

class MediaRepository 
{
    private DB $database;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function save(MediaEntity $media): MediaEntity 
    {
        return DB::transaction(function() use ($media) {
            if ($media->isNew()) {
                $data = $this->database->table('media')->insert($media->toArray());
            } else {
                $data = $this->database->table('media')
                    ->where('id', $media->getId())
                    ->update($media->toArray());
            }

            $this->logger->logMediaChange($media);
            return MediaEntity::fromArray($data);
        });
    }

    public function findWithSecurity(int $id, SecurityContext $context): MediaEntity 
    {
        $media = $this->database->table('media')
            ->where('id', $id)
            ->first();

        if (!$media) {
            throw new MediaException("Media not found: {$id}");
        }

        if (!$this->checkAccess($media, $context)) {
            throw new SecurityException("Access denied to media: {$id}");
        }

        return MediaEntity::fromArray($media);
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        return DB::transaction(function() use ($id, $context) {
            $media = $this->findWithSecurity($id, $context);
            
            $this->database->table('media')
                ->where('id', $id)
                ->delete();

            $this->logger->logMediaDeletion($media);
            return true;
        });
    }
}

class FileProcessor 
{
    private array $config;
    private StorageManager $storage;

    public function processUpload(UploadedFile $file): ProcessedFile 
    {
        $this->validateFile($file);
        $this->scanForThreats($file);
        
        $processed = $this->processFile($file);
        $this->generateVariants($processed);

        return $processed;
    }

    public function createVariant(
        MediaEntity $media,
        string $variant,
        array $options
    ): MediaVariant {
        $original = $this->storage->get($media->getPath());
        
        $processed = match($variant) {
            'thumbnail' => $this->createThumbnail($original, $options),
            'preview' => $this->createPreview($original, $options),
            'optimized' => $this->optimizeMedia($original, $options),
            default => throw new MediaException("Unknown variant: {$variant}")
        };

        return new MediaVariant($processed, $variant, $options);
    }

    private function validateFile(UploadedFile $file): void 
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), $this->config['allowed_types'])) {
            throw new ValidationException('Unsupported file type');
        }

        if ($file->getSize() > $this->config['max_size']) {
            throw new ValidationException('File too large');
        }
    }

    private function scanForThreats(UploadedFile $file): void 
    {
        // Implement virus scanning and security checks
    }

    private function processFile(UploadedFile $file): ProcessedFile 
    {
        $path = $this->generateSecurePath($file);
        
        $processed = new ProcessedFile([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath())
        ]);

        $this->storage->store($file, $path);
        return $processed;
    }

    private function generateVariants(ProcessedFile $file): void 
    {
        foreach ($this->config['auto_variants'] as $variant => $options) {
            try {
                $this->createVariant($file, $variant, $options);
            } catch (\Exception $e) {
                // Log error but continue processing other variants
                $this->logger->error("Failed to create variant: {$variant}", [
                    'file' => $file->getPath(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}

class MediaEntity 
{
    private ?int $id;
    private string $name;
    private string $path;
    private string $mimeType;
    private int $size;
    private string $hash;
    private array $metadata;
    private array $variants;

    public static function create(array $data): self 
    {
        $instance = new self();
        $instance->fill($data);
        return $instance;
    }

    public static function fromArray(array $data): self 
    {
        $instance = new self();
        $instance->fill($data);
        $instance->id = $data['id'];
        return $instance;
    }

    public function isNew(): bool 
    {
        return $this->id === null;
    }

    public function getId(): ?int 
    {
        return $this->id;
    }

    public function getPath(): string 
    {
        return $this->path;
    }

    public function toArray(): array 
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'hash' => $this->hash,
            'metadata' => json_encode($this->metadata),
            'variants' => json_encode($this->variants)
        ];
    }

    private function fill(array $data): void 
    {
        $this->name = $data['name'];
        $this->path = $data['path'];
        $this->mimeType = $data['mime_type'];
        $this->size = $data['size'];
        $this->hash = $data['hash'];
        $this->metadata = $data['metadata'] ?? [];
        $this->variants = $data['variants'] ?? [];
    }
}
