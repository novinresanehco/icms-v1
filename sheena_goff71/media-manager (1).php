namespace App\Core\Media;

class MediaManager implements MediaInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageManager $storage;
    private ProcessingQueue $queue;
    private MediaRepository $repository;
    private array $config;

    public function store(UploadedFile $file, array $metadata = []): MediaFile 
    {
        return $this->security->executeCriticalOperation(
            new StoreMediaOperation($file),
            function() use ($file, $metadata) {
                // Validate file
                $this->validateFile($file);
                
                // Generate secure filename
                $filename = $this->generateSecureFilename($file);
                
                // Store file securely
                $path = $this->storeSecurely($file, $filename);
                
                // Create database record
                $media = $this->repository->create([
                    'filename' => $filename,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'hash' => hash_file('sha256', $file->getPathname()),
                    'metadata' => array_merge($metadata, [
                        'original_name' => $file->getClientOriginalName(),
                        'uploaded_at' => now(),
                        'uploader_id' => auth()->id()
                    ])
                ]);

                // Queue for processing
                $this->queueForProcessing($media);
                
                return $media;
            }
        );
    }

    public function retrieve(int $id): MediaFile 
    {
        return $this->security->executeCriticalOperation(
            new RetrieveMediaOperation($id),
            function() use ($id) {
                $media = $this->repository->findOrFail($id);
                
                // Verify permissions
                $this->verifyAccess($media);
                
                // Verify file integrity
                $this->verifyIntegrity($media);
                
                // Log access
                $this->logAccess($media);
                
                return $media;
            }
        );
    }

    public function delete(int $id): void 
    {
        $this->security->executeCriticalOperation(
            new DeleteMediaOperation($id),
            function() use ($id) {
                $media = $this->repository->findOrFail($id);
                
                // Verify permissions
                $this->verifyAccess($media, 'delete');
                
                // Delete file
                $this->storage->delete($media->path);
                
                // Delete database record
                $this->repository->delete($id);
                
                // Clean up processed versions
                $this->cleanupProcessedVersions($media);
                
                // Log deletion
                $this->logDeletion($media);
            }
        );
    }

    protected function validateFile(UploadedFile $file): void 
    {
        // Validate mime type
        if (!in_array($file->getMimeType(), $this->config['allowed_mimes'])) {
            throw new InvalidFileTypeException();
        }

        // Validate size
        if ($file->getSize() > $this->config['max_size']) {
            throw new FileTooLargeException();
        }

        // Scan for malware
        if (!$this->scanFile($file)) {
            throw new MaliciousFileException();
        }
    }

    protected function generateSecureFilename(UploadedFile $file): string 
    {
        return sprintf(
            '%s_%s.%s',
            time(),
            bin2hex(random_bytes(16)),
            $file->getClientOriginalExtension()
        );
    }

    protected function storeSecurely(UploadedFile $file, string $filename): string 
    {
        // Determine storage path
        $path = $this->getStoragePath($filename);
        
        // Store with encryption
        $this->storage->storeEncrypted(
            $file->getRealPath(),
            $path,
            $this->config['encryption_key']
        );
        
        return $path;
    }

    protected function queueForProcessing(MediaFile $media): void 
    {
        foreach ($this->config['processors'] as $processor) {
            $this->queue->push(new ProcessMediaJob(
                $media->id,
                $processor
            ));
        }
    }

    protected function verifyAccess(MediaFile $media, string $action = 'read'): void 
    {
        if (!$this->security->can($action, $media)) {
            throw new UnauthorizedAccessException();
        }
    }

    protected function verifyIntegrity(MediaFile $media): void 
    {
        $currentHash = hash_file(
            'sha256',
            $this->storage->path($media->path)
        );
        
        if ($currentHash !== $media->hash) {
            throw new FileIntegrityException();
        }
    }

    protected function cleanupProcessedVersions(MediaFile $media): void 
    {
        foreach ($media->processedVersions as $version) {
            $this->storage->delete($version->path);
        }
    }

    protected function scanFile(UploadedFile $file): bool 
    {
        return $this->security->scanFile(
            $file->getRealPath(),
            $this->config['malware_scanner']
        );
    }

    protected function logAccess(MediaFile $media): void 
    {
        $this->security->logAccess('media', [
            'media_id' => $media->id,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'timestamp' => now()
        ]);
    }

    protected function logDeletion(MediaFile $media): void 
    {
        $this->security->logDeletion('media', [
            'media_id' => $media->id,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'timestamp' => now()
        ]);
    }

    protected function getStoragePath(string $filename): string 
    {
        return sprintf(
            '%s/%s/%s',
            date('Y/m/d'),
            substr(md5($filename), 0, 2),
            $filename
        );
    }
}
