<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{DB, Storage, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{MediaManagerInterface, StorageInterface};
use App\Core\Exceptions\{MediaException, SecurityException};

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private StorageInterface $storage;
    private MediaRepository $repository;
    private MediaProcessor $processor;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        StorageInterface $storage,
        MediaRepository $repository,
        MediaProcessor $processor,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->repository = $repository;
        $this->processor = $processor;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function uploadMedia(UploadedFile $file, array $options = []): MediaEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeMediaUpload($file, $options),
            ['action' => 'upload_media', 'options' => $options]
        );
    }

    public function processMedia(int $id, array $operations = []): MediaEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeMediaProcessing($id, $operations),
            ['action' => 'process_media', 'id' => $id, 'operations' => $operations]
        );
    }

    protected function executeMediaUpload(UploadedFile $file, array $options): MediaEntity
    {
        $this->validateMediaFile($file);
        $this->validateStorageCapacity();

        DB::beginTransaction();
        
        try {
            $hash = $this->calculateFileHash($file);
            $this->checkDuplicateFile($hash);

            $path = $this->generateSecurePath($file);
            $metadata = $this->extractMediaMetadata($file);
            
            $this->sanitizeFile($file);
            $this->storage->store($file, $path);

            $media = $this->repository->create([
                'path' => $path,
                'hash' => $hash,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $metadata,
                'status' => 'pending',
                'options' => $options
            ]);

            $this->processInitialMedia($media);
            $this->updateMediaCache($media);

            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->cleanup(['path' => $path ?? null]);
            throw new MediaException('Media upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function executeMediaProcessing(int $id, array $operations): MediaEntity
    {
        $this->validateOperations($operations);

        try {
            $media = $this->repository->find($id);
            
            if (!$media) {
                throw new MediaException('Media not found');
            }

            $this->createMediaBackup($media);
            
            foreach ($operations as $operation) {
                $media = $this->processor->process($media, $operation);
                $this->validateProcessedMedia($media);
            }

            $media->status = 'processed';
            $this->repository->save($media);
            $this->updateMediaCache($media);

            return $media;

        } catch (\Exception $e) {
            $this->restoreMediaBackup($media ?? null);
            throw new MediaException('Media processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateMediaFile(UploadedFile $file): void
    {
        if (!$this->validator->validateFile($file, $this->config['allowed_types'])) {
            throw new SecurityException('Invalid media file');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File size exceeds limit');
        }
    }

    protected function validateStorageCapacity(): void
    {
        $usage = $this->storage->getUsage();
        
        if ($usage > $this->config['storage_limit']) {
            throw new MediaException('Storage capacity exceeded');
        }
    }

    protected function calculateFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    protected function checkDuplicateFile(string $hash): void
    {
        if ($this->repository->findByHash($hash)) {
            throw new MediaException('Duplicate file detected');
        }
    }

    protected function generateSecurePath(UploadedFile $file): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            date('Y/m'),
            uniqid('media_', true),
            bin2hex(random_bytes(8)),
            $file->getClientOriginalExtension()
        );
    }

    protected function extractMediaMetadata(UploadedFile $file): array
    {
        return array_merge(
            $this->processor->extractMetadata($file),
            [
                'original_name' => $file->getClientOriginalName(),
                'uploaded_at' => now()->toAtomString(),
                'uploaded_by' => auth()->id()
            ]
        );
    }

    protected function sanitizeFile(UploadedFile $file): void
    {
        if (!$this->processor->sanitize($file)) {
            throw new SecurityException('File sanitization failed');
        }
    }

    protected function processInitialMedia(MediaEntity $media): void
    {
        if ($this->config['process_on_upload']) {
            $this->processor->processInitial($media);
        }
    }

    protected function createMediaBackup(MediaEntity $media): void
    {
        $this->storage->backup(
            $media->path,
            sprintf('backups/%s_%s', $media->id, now()->timestamp)
        );
    }

    protected function restoreMediaBackup(MediaEntity $media = null): void
    {
        if ($media) {
            $this->storage->restoreLatestBackup($media->path);
        }
    }

    protected function validateProcessedMedia(MediaEntity $media): void
    {
        if (!$this->validator->validateProcessedMedia($media)) {
            throw new MediaException('Processed media validation failed');
        }
    }

    protected function updateMediaCache(MediaEntity $media): void
    {
        Cache::tags(['media'])
            ->put("media:{$media->id}", $media, $this->config['cache_ttl']);
    }

    protected function cleanup(array $data): void
    {
        if (isset($data['path'])) {
            $this->storage->delete($data['path']);
        }
    }
}
