namespace App\Core\Media;

class MediaManager implements MediaManagerInterface
{
    private MediaRepository $repository;
    private SecurityManager $security;
    private StorageManager $storage;
    private ValidationService $validator;
    private CacheManager $cache;
    private EventDispatcher $events;
    private AuditLogger $logger;

    public function __construct(
        MediaRepository $repository,
        SecurityManager $security,
        StorageManager $storage,
        ValidationService $validator,
        CacheManager $cache,
        EventDispatcher $events,
        AuditLogger $logger
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->events = $events;
        $this->logger = $logger;
    }

    public function store(UploadedFile $file, array $options = []): Media
    {
        return $this->security->executeCriticalOperation(new StoreMediaOperation(
            $file,
            $options,
            function() use ($file, $options) {
                // Validate file
                $this->validator->validateFile($file, MediaRules::upload());
                
                // Generate secure filename
                $filename = $this->generateSecureFilename($file);
                
                // Store file securely
                $path = $this->storage->secureStore(
                    $file,
                    $filename,
                    $options['visibility'] ?? 'private'
                );
                
                // Create media record
                $media = $this->repository->create([
                    'filename' => $filename,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'metadata' => $this->extractMetadata($file),
                    'visibility' => $options['visibility'] ?? 'private'
                ]);
                
                // Process media if needed
                if (!empty($options['process'])) {
                    $this->processMedia($media, $options['process']);
                }
                
                // Dispatch events
                $this->events->dispatch(new MediaStored($media));
                
                return $media;
            }
        ));
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(new DeleteMediaOperation(
            $id,
            function() use ($id) {
                $media = $this->repository->findOrFail($id);
                
                // Delete file
                $this->storage->delete($media->path);
                
                // Delete thumbnails if exist
                $this->deleteThumbnails($media);
                
                // Delete record
                $result = $this->repository->delete($media);
                
                // Clear cache
                $this->cache->forget($this->getCacheKey($id));
                
                // Dispatch events
                $this->events->dispatch(new MediaDeleted($media));
                
                return $result;
            }
        ));
    }

    public function setVisibility(int $id, string $visibility): Media
    {
        return $this->security->executeCriticalOperation(new UpdateMediaVisibilityOperation(
            $id,
            $visibility,
            function() use ($id, $visibility) {
                $media = $this->repository->findOrFail($id);
                
                // Update storage visibility
                $this->storage->setVisibility($media->path, $visibility);
                
                // Update media record
                $media = $this->repository->update($media, [
                    'visibility' => $visibility
                ]);
                
                // Clear cache
                $this->cache->forget($this->getCacheKey($id));
                
                // Dispatch events
                $this->events->dispatch(new MediaVisibilityUpdated($media));
                
                return $media;
            }
        ));
    }

    public function generateThumbnail(int $id, array $dimensions): Media
    {
        return $this->security->executeCriticalOperation(new GenerateThumbnailOperation(
            $id,
            $dimensions,
            function() use ($id, $dimensions) {
                $media = $this->repository->findOrFail($id);
                
                // Generate thumbnail
                $thumbnail = $this->processImage($media, $dimensions);
                
                // Store thumbnail info
                $media = $this->repository->update($media, [
                    'thumbnails' => array_merge(
                        $media->thumbnails ?? [],
                        [$dimensions['width'] . 'x' . $dimensions['height'] => $thumbnail]
                    )
                ]);
                
                // Clear cache
                $this->cache->forget($this->getCacheKey($id));
                
                return $media;
            }
        ));
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            hash('sha256', uniqid('', true)),
            time(),
            $file->getClientOriginalExtension()
        );
    }

    private function extractMetadata(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'hash' => hash_file('sha256', $file->getRealPath()),
            'extracted_at' => now()->toDateTimeString()
        ];
    }

    private function processMedia(Media $media, array $options): void
    {
        if ($this->isImage($media)) {
            $this->processImage($media, $options);
        }
    }

    private function processImage(Media $media, array $options): array
    {
        $image = Image::make($this->storage->get($media->path));
        
        // Apply processing options
        if (!empty($options['resize'])) {
            $image->resize(
                $options['resize']['width'],
                $options['resize']['height'],
                function ($constraint) use ($options) {
                    if (!empty($options['resize']['aspect'])) {
                        $constraint->aspectRatio();
                    }
                    if (!empty($options['resize']['upsize'])) {
                        $constraint->upsize();
                    }
                }
            );
        }
        
        // Generate thumbnail path
        $thumbnailPath = sprintf(
            'thumbnails/%s/%dx%d_%s',
            date('Y/m'),
            $options['resize']['width'],
            $options['resize']['height'],
            basename($media->path)
        );
        
        // Store thumbnail
        $this->storage->put(
            $thumbnailPath,
            $image->encode()->getEncoded(),
            $media->visibility
        );
        
        return [
            'path' => $thumbnailPath,
            'width' => $image->width(),
            'height' => $image->height(),
            'size' => strlen($image->encode()->getEncoded())
        ];
    }

    private function deleteThumbnails(Media $media): void
    {
        if (!empty($media->thumbnails)) {
            foreach ($media->thumbnails as $thumbnail) {
                $this->storage->delete($thumbnail['path']);
            }
        }
    }

    private function isImage(Media $media): bool
    {
        return strpos($media->mime_type, 'image/') === 0;
    }

    private function getCacheKey(int $id): string
    {
        return "media:{$id}";
    }
}
