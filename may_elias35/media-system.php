```php
namespace App\Core\Media;

class MediaManager implements MediaInterface
{
    private SecurityManager $security;
    private FileValidator $validator;
    private StorageManager $storage;
    private ImageProcessor $processor;
    private AuditLogger $audit;

    public function store(UploadedFile $file): MediaFile
    {
        return $this->security->executeProtected(function() use ($file) {
            // Validate file
            $this->validator->validateFile($file);
            
            // Generate secure filename
            $filename = $this->security->generateSecureFilename($file);
            
            // Process and store file
            $processed = $this->processFile($file, $filename);
            $path = $this->storage->store($processed, $filename);
            
            // Create media record
            $media = new MediaFile([
                'filename' => $filename,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
                'hash' => hash_file('sha256', $processed->path())
            ]);

            $this->audit->logMediaUpload($media);
            return $media;
        });
    }

    private function processFile(UploadedFile $file, string $filename): File
    {
        if ($this->isImage($file)) {
            return $this->processor->processImage($file, [
                'max_width' => 2048,
                'max_height' => 2048,
                'optimize' => true
            ]);
        }
        return $file;
    }

    public function retrieve(int $mediaId): MediaFile
    {
        return $this->security->executeProtected(function() use ($mediaId) {
            $media = MediaFile::findOrFail($mediaId);
            
            // Verify file integrity
            $this->verifyFileIntegrity($media);
            
            // Check permissions
            $this->security->validateAccess('media', 'read', $media);
            
            return $media;
        });
    }

    private function verifyFileIntegrity(MediaFile $media): void
    {
        $currentHash = hash_file('sha256', $media->getFullPath());
        
        if ($currentHash !== $media->hash) {
            $this->audit->logIntegrityFailure($media);
            throw new FileIntegrityException();
        }
    }
}

class FileValidator implements ValidatorInterface
{
    private array $allowedMimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain'
    ];

    private array $maxSizes = [
        'image/*' => 5242880, // 5MB
        'application/pdf' => 10485760, // 10MB
        'text/plain' => 1048576 // 1MB
    ];

    public function validateFile(UploadedFile $file): void
    {
        $this->validateMimeType($file);
        $this->validateFileSize($file);
        $this->scanForThreats($file);
    }

    private function validateMimeType(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedMimes)) {
            throw new InvalidFileTypeException();
        }
    }

    private function validateFileSize(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        $maxSize = $this->getMaxSize($mime);
        
        if ($file->getSize() > $maxSize) {
            throw new FileTooLargeException();
        }
    }

    private function scanForThreats(UploadedFile $file): void
    {
        // Implement virus scanning
        if (!$this->security->scanFile($file)) {
            throw new MaliciousFileException();
        }
    }
}

class ImageProcessor
{
    private SecurityManager $security;
    private ValidationService $validator;

    public function processImage(UploadedFile $file, array $options): File
    {
        $image = Image::make($file->path());
        
        // Validate image dimensions
        $this->validator->validateDimensions($image, $options);
        
        // Resize if needed
        if ($image->width() > $options['max_width'] || 
            $image->height() > $options['max_height']) {
            $image->resize($options['max_width'], $options['max_height'], function($c) {
                $c->aspectRatio();
                $c->upsize();
            });
        }
        
        // Optimize if requested
        if ($options['optimize']) {
            $this->optimizeImage($image);
        }
        
        // Strip metadata for security
        $this->stripMetadata($image);
        
        return $image;
    }

    private function optimizeImage(Image $image): void
    {
        $image->encode(null, 85); // Optimize quality
    }

    private function stripMetadata(Image $image): void
    {
        $image->strip();
    }
}
```
