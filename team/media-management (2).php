namespace App\Core\Media;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private MediaRepository $repository;
    private FileSystem $fileSystem;
    private ImageProcessor $imageProcessor;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        FileSystem $fileSystem,
        ImageProcessor $imageProcessor,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->fileSystem = $fileSystem;
        $this->imageProcessor = $imageProcessor;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function uploadMedia(UploadedFile $file, array $metadata, SecurityContext $context): MediaResult
    {
        return $this->security->executeSecureOperation(
            function() use ($file, $metadata, $context) {
                // Validate file
                $this->validateFile($file);
                
                // Process upload with transaction protection
                return DB::transaction(function() use ($file, $metadata, $context) {
                    // Store file
                    $path = $this->fileSystem->store($file);
                    
                    // Create thumbnails if image
                    $thumbnails = [];
                    if ($this->isImage($file)) {
                        $thumbnails = $this->createThumbnails($file);
                    }
                    
                    // Create media record
                    $media = $this->repository->create([
                        'path' => $path,
                        'filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'metadata' => $metadata,
                        'thumbnails' => $thumbnails
                    ]);
                    
                    // Log upload
                    $this->auditLogger->logMediaUpload($media, $context);
                    
                    return new MediaResult($media);
                });
            },
            $context
        );
    }

    public function deleteMedia(string $id, SecurityContext $context): void
    {
        $this->security->executeSecureOperation(
            function() use ($id, $context) {
                DB::transaction(function() use ($id, $context) {
                    // Load media
                    $media = $this->repository->findOrFail($id);
                    
                    // Delete files
                    $this->fileSystem->delete($media->getPath());
                    foreach ($media->getThumbnails() as $thumbnail) {
                        $this->fileSystem->delete($thumbnail);
                    }
                    
                    // Delete record
                    $this->repository->delete($media);
                    
                    // Log deletion
                    $this->auditLogger->logMediaDeletion($media, $context);
                });
            },
            $context
        );
    }

    public function getMedia(string $id, SecurityContext $context): MediaResult
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                $media = $this->repository->findOrFail($id);
                $this->auditLogger->logMediaAccess($media, $context);
                return new MediaResult($media);
            },
            $context
        );
    }

    private function validateFile(UploadedFile $file): void
    {
        $maxSize = config('media.max_size');
        $allowedTypes = config('media.allowed_types');

        if ($file->getSize() > $maxSize) {
            throw new ValidationException("File size exceeds maximum allowed size");
        }

        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw new ValidationException("File type not allowed");
        }
    }

    private function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    private function createThumbnails(UploadedFile $file): array
    {
        $thumbnails = [];
        $sizes = config('media.thumbnail_sizes');

        foreach ($sizes as $size) {
            $thumbnails[$size] = $this->imageProcessor->createThumbnail(
                $file,
                $size['width'],
                $size['height']
            );
        }

        return $thumbnails;
    }
}
