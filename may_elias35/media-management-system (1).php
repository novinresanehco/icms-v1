namespace App\Core\Media;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private StorageService $storage;
    private ValidationService $validator;
    private ProcessingService $processor;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        ValidationService $validator,
        ProcessingService $processor,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function upload(UploadedFile $file): Media
    {
        return $this->security->executeCriticalOperation(new class($file, $this->storage, $this->validator, $this->processor) implements CriticalOperation {
            private UploadedFile $file;
            private StorageService $storage;
            private ValidationService $validator;
            private ProcessingService $processor;

            public function __construct(
                UploadedFile $file,
                StorageService $storage,
                ValidationService $validator,
                ProcessingService $processor
            ) {
                $this->file = $file;
                $this->storage = $storage;
                $this->validator = $validator;
                $this->processor = $processor;
            }

            public function execute(): OperationResult
            {
                $hash = hash_file('sha256', $this->file->getRealPath());
                $sanitizedFilename = $this->sanitizeFilename($this->file->getClientOriginalName());
                
                $media = new Media([
                    'hash' => $hash,
                    'filename' => $sanitizedFilename,
                    'mime_type' => $this->file->getMimeType(),
                    'size' => $this->file->getSize(),
                    'metadata' => $this->extractMetadata()
                ]);

                $path = $this->storage->store($this->file, $hash);
                $media->path = $path;

                if ($this->shouldProcess($media)) {
                    $this->processor->process($media);
                }

                return new OperationResult($media);
            }

            public function getValidationRules(): array
            {
                return [
                    'file' => [
                        'required',
                        'file',
                        'mimes:jpeg,png,gif,pdf,doc,docx',
                        'max:10240',
                        function ($attribute, $value, $fail) {
                            if (!$this->validator->validateFileContent($value)) {
                                $fail('The file content is invalid or potentially malicious.');
                            }
                        }
                    ]
                ];
            }

            public function getData(): array
            {
                return [
                    'original_name' => $this->file->getClientOriginalName(),
                    'mime_type' => $this->file->getMimeType(),
                    'size' => $this->file->getSize()
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['media.upload'];
            }

            public function getRateLimitKey(): string
            {
                return 'media:upload';
            }

            private function sanitizeFilename(string $filename): string
            {
                $info = pathinfo($filename);
                $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($info['filename']));
                return $filename . '.' . $info['extension'];
            }

            private function extractMetadata(): array
            {
                return [
                    'dimensions' => $this->processor->getDimensions($this->file),
                    'created_at' => $this->file->getMTime(),
                    'exif' => $this->processor->getExifData($this->file)
                ];
            }

            private function shouldProcess(Media $media): bool
            {
                return in_array($media->mime_type, [
                    'image/jpeg',
                    'image/png',
                    'image/gif'
                ]);
            }
        });
    }

    public function process(Media $media): void
    {
        $this->security->executeCriticalOperation(new class($media, $this->processor, $this->storage) implements CriticalOperation {
            private Media $media;
            private ProcessingService $processor;
            private StorageService $storage;

            public function __construct(Media $media, ProcessingService $processor, StorageService $storage)
            {
                $this->media = $media;
                $this->processor = $processor;
                $this->storage = $storage;
            }

            public function execute(): OperationResult
            {
                $variants = $this->processor->generateVariants($this->media);
                
                foreach ($variants as $variant) {
                    $path = $this->storage->storeVariant($variant, $this->media->hash);
                    $this->media->addVariant($variant->type, $path);
                }

                return new OperationResult($this->media);
            }

            public function getValidationRules(): array
            {
                return ['media_id' => 'required|exists:media'];
            }

            public function getData(): array
            {
                return ['media_id' => $this->media->id];
            }

            public function getRequiredPermissions(): array
            {
                return ['media.process'];
            }

            public function getRateLimitKey(): string
            {
                return "media:process:{$this->media->id}";
            }
        });
    }

    public function optimize(Media $media): void
    {
        $this->security->executeCriticalOperation(new class($media, $this->processor, $this->storage) implements CriticalOperation {
            private Media $media;
            private ProcessingService $processor;
            private StorageService $storage;

            public function __construct(Media $media, ProcessingService $processor, StorageService $storage)
            {
                $this->media = $media;
                $this->processor = $processor;
                $this->storage = $storage;
            }

            public function execute(): OperationResult
            {
                $optimizedFile = $this->processor->optimize($this->media);
                $this->storage->replace($this->media->path, $optimizedFile);
                
                return new OperationResult($this->media);
            }

            public function getValidationRules(): array
            {
                return ['media_id' => 'required|exists:media'];
            }

            public function getData(): array
            {
                return ['media_id' => $this->media->id];
            }

            public function getRequiredPermissions(): array
            {
                return ['media.optimize'];
            }

            public function getRateLimitKey(): string
            {
                return "media:optimize:{$this->media->id}";
            }
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(new class($id, $this->storage) implements CriticalOperation {
            private int $id;
            private StorageService $storage;

            public function __construct(int $id, StorageService $storage)
            {
                $this->id = $id;
                $this->storage = $storage;
            }

            public function execute(): OperationResult
            {
                $media = Media::findOrFail($this->id);
                $this->storage->delete($media->path);
                
                foreach ($media->variants as $variant) {
                    $this->storage->delete($variant->path);
                }
                
                $media->delete();
                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return ['id' => 'required|exists:media'];
            }

            public function getData(): array
            {
                return ['id' => $this->id];
            }

            public function getRequiredPermissions(): array
            {
                return ['media.delete'];
            }

            public function getRateLimitKey(): string
            {
                return "media:delete:{$this->id}";
            }
        });
    }
}
