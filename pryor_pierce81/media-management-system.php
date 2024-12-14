<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use App\Core\Security\SecurityContext;
use App\Core\Interfaces\MediaManagerInterface;

class MediaManager implements MediaManagerInterface 
{
    private SecurityContext $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $config;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    public function __construct(
        SecurityContext $security,
        ValidationService $validator,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function upload(UploadedFile $file, array $options = []): MediaFile
    {
        $this->validateUpload($file);
        
        return DB::transaction(function() use ($file, $options) {
            // Generate secure filename
            $filename = $this->generateSecureFilename($file);
            
            // Process and store file
            $path = $this->processAndStore($file, $filename, $options);
            
            // Create database record
            $media = MediaFile::create([
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
                'disk' => $options['disk'] ?? 'local',
                'uploaded_by' => $this->security->getCurrentUserId(),
                'metadata' => $this->extractMetadata($file)
            ]);

            // Generate thumbnails if needed
            if ($this->shouldGenerateThumbnails($file)) {
                $this->generateThumbnails($media);
            }

            return $media;
        });
    }

    public function download(int $mediaId): StreamedResponse
    {
        $media = $this->findOrFail($mediaId);
        
        $this->security->validateAccess('media.download', $media);
        
        return Storage::disk($media->disk)
            ->download($media->path, $media->original_name);
    }

    public function delete(int $mediaId): bool
    {
        return DB::transaction(function() use ($mediaId) {
            $media = $this->findOrFail($mediaId);
            
            $this->security->validateAccess('media.delete', $media);
            
            // Delete file and thumbnails
            Storage::disk($media->disk)->delete($media->path);
            $this->deleteThumbnails($media);
            
            // Remove database record
            return $media->delete();
        });
    }

    public function optimize(int $mediaId): MediaFile
    {
        $media = $this->findOrFail($mediaId);
        
        if (!$this->isOptimizable($media)) {
            return $media;
        }

        return DB::transaction(function() use ($media) {
            $optimizedPath = $this->optimizeFile($media);
            
            $media->update([
                'path' => $optimizedPath,
                'size' => Storage::disk($media->disk)->size($optimizedPath),
                'metadata' => array_merge(
                    $media->metadata,
                    ['optimized' => true]
                )
            ]);

            return $media;
        });
    }

    protected function validateUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new UploadException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new UploadException('Invalid file type');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new UploadException('File too large');
        }

        $this->validateFileContent($file);
    }

    protected function validateFileContent(UploadedFile $file): void
    {
        // Scan for malware
        if (!$this->scanFile($file)) {
            throw new SecurityException('File failed security scan');
        }

        // Check for malicious content
        if ($this->containsMaliciousContent($file)) {
            throw new SecurityException('Malicious content detected');
        }
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            Str::random(40),
            time(),
            $file->getClientOriginalExtension()
        );
    }

    protected function processAndStore(
        UploadedFile $file,
        string $filename,
        array $options
    ): string {
        $disk = $options['disk'] ?? 'local';
        $path = $options['path'] ?? date('Y/m/d');
        
        return Storage::disk($disk)->putFileAs(
            $path,
            $file,
            $filename,
            ['visibility' => 'private']
        );
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        $metadata = [
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->path())
        ];

        if ($this->isImage($file)) {
            $metadata = array_merge(
                $metadata,
                $this->extractImageMetadata($file)
            );
        }

        return $metadata;
    }

    protected function extractImageMetadata(UploadedFile $file): array
    {
        $image = Image::make($file->path());
        
        return [
            'width' => $image->width(),
            'height' => $image->height(),
            'exif' => $image->exif() ?? []
        ];
    }

    protected function generateThumbnails(MediaFile $media): void
    {
        if (!$this->isImage($media)) {
            return;
        }

        foreach ($this->config['thumbnail_sizes'] as $size => $dimensions) {
            $this->generateThumbnail($media, $size, $dimensions);
        }
    }

    protected function generateThumbnail(
        MediaFile $media,
        string $size,
        array $dimensions
    ): void {
        $image = Image::make(
            Storage::disk($media->disk)->path($media->path)
        );

        $thumbnail = $image->fit(
            $dimensions['width'],
            $dimensions['height']
        );

        $thumbnailPath = $this->getThumbnailPath($media, $size);
        
        Storage::disk($media->disk)->put(
            $thumbnailPath,
            $thumbnail->encode()
        );

        $media->thumbnails()->create([
            'size' => $size,
            'path' => $thumbnailPath,
            'width' => $dimensions['width'],
            'height' => $dimensions['height']
        ]);
    }

    protected function optimizeFile(MediaFile $media): string
    {
        $originalPath = Storage::disk($media->disk)->path($media->path);
        $optimizedPath = $this->getOptimizedPath($media);
        
        if ($this->isImage($media)) {
            $this->optimizeImage($originalPath, $optimizedPath);
        }

        return $optimizedPath;
    }

    protected function scanFile(UploadedFile $file): bool
    {
        // Implement antivirus scanning
        return true;
    }

    protected function containsMaliciousContent(UploadedFile $file): bool
    {
        // Implement content scanning
        return false;
    }

    protected function isImage(mixed $file): bool
    {
        $mime = $file instanceof UploadedFile
            ? $file->getMimeType()
            : $file->mime_type;

        return Str::startsWith($mime, 'image/');
    }

    protected function shouldGenerateThumbnails(UploadedFile $file): bool
    {
        return $this->isImage($file) && 
               $this->config['generate_thumbnails'] ?? true;
    }
}
