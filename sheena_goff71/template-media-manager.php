<?php

namespace App\Core\Template\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use App\Core\Template\Exceptions\MediaException;

class MediaManager
{
    private MediaStorage $storage;
    private MediaProcessor $processor;
    private MediaValidator $validator;
    private array $config;

    public function __construct(
        MediaStorage $storage,
        MediaProcessor $processor,
        MediaValidator $validator,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->processor = $processor;
        $this->validator = $validator;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Upload media file
     *
     * @param UploadedFile $file
     * @param array $options
     * @return MediaFile
     * @throws MediaException
     */
    public function upload(UploadedFile $file, array $options = []): MediaFile
    {
        // Validate file
        $this->validator->validate($file);

        // Process file
        $processedFile = $this->processor->process($file, $options);

        // Store file
        $path = $this->storage->store($processedFile);

        // Create media record
        return new MediaFile([
            'path' => $path,
            'filename' => $processedFile->getClientOriginalName(),
            'mime_type' => $processedFile->getMimeType(),
            'size' => $processedFile->getSize(),
            'metadata' => $this->extractMetadata($processedFile)
        ]);
    }

    /**
     * Get media file
     *
     * @param string $path
     * @param array $options
     * @return MediaFile
     */
    public function get(string $path, array $options = []): MediaFile
    {
        $file = $this->storage->get($path);
        
        if (isset($options['transform'])) {
            $file = $this->processor->transform($file, $options['transform']);
        }

        return $file;
    }

    /**
     * Delete media file
     *
     * @param string $path
     * @return bool
     */
    public function delete(string $path): bool
    {
        return $this->storage->delete($path);
    }

    /**
     * Generate thumbnail
     *
     * @param MediaFile $file
     * @param array $options
     * @return MediaFile
     */
    public function generateThumbnail(MediaFile $file, array $options = []): MediaFile
    {
        if (!$this->isImage($file)) {
            throw new MediaException("Cannot generate thumbnail for non-image file");
        }

        $options = array_merge($this->config['thumbnail_defaults'], $options);
        return $this->processor->createThumbnail($file, $options);
    }

    /**
     * Check if file is image
     *
     * @param MediaFile $file
     * @return bool
     */
    public function isImage(MediaFile $file): bool
    {
        return Str::startsWith($file->mime_type, 'image/');
    }

    /**
     * Extract file metadata
     *
     * @param UploadedFile $file
     * @return array
     */
    protected function extractMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'upload_time' => now()
        ];

        if ($this->isImage($file)) {
            $image = Image::make($file);
            $metadata['dimensions'] = [
                'width' => $image->width(),
                'height' => $image->height()
            ];
        }

        return $metadata;
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'max_file_size' => 10 * 1024 * 1024, // 10MB
            'allowed_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'application/pdf'
            ],
            'thumbnail_defaults' => [
                'width' => 150,
                'height' => 150,
                'maintain_aspect' => true
            ],
            'storage' => [
                'disk' => 'public',
                'path' => 'media'
            ]
        ];
    }
}

class MediaProcessor
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Process uploaded file
     *
     * @param UploadedFile $file
     * @param array $options
     * @return UploadedFile
     */
    public function process(UploadedFile $file, array $options = []): UploadedFile
    {
        if (Str::startsWith($file->getMimeType(), 'image/')) {
            return $this->processImage($file, $options);
        }

        return $file;
    }

    /**
     * Process image file
     *
     * @param UploadedFile $file
     * @param array $options
     * @return UploadedFile
     */
    protected function processImage(UploadedFile $file, array $options = []): UploadedFile
    {
        $image = Image::make($file);

        // Resize if needed
        if (isset($options['max_width']) || isset($options['max_height'])) {
            $image->resize(
                $options['max_width'] ?? null,
                $options['max_height'] ?? null,
                function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                }
            );
        }

        // Optimize
        if ($options['optimize'] ?? true) {
            $image->optimize();
        }

        // Save processed image
        $tempPath = tempnam(sys_get_temp_dir(), 'media_');
        $image->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $file->getClientOriginalName(),
            $file->getMimeType(),
            $file->getError(),
            true
        );
    }

    /**
     * Create thumbnail
     *
     * @param MediaFile $file
     * @param array $options
     * @return MediaFile
     */
    public function createThumbnail(MediaFile $file, array $options): MediaFile
    {
        $image = Image::make(Storage::disk($file->disk)->path($file->path));

        $image->fit(
            $options['width'],
            $options['height'],
            function ($constraint) use ($options) {
                if ($options['maintain_aspect']) {
                    $constraint->aspectRatio();
                }
            }
        );

        $thumbnailPath = 'thumbnails/' . basename($file->path);
        Storage::disk($file->disk)->put(
            $thumbnailPath,
            (string) $image->encode()
        );

        return new MediaFile([
            'path' => $thumbnailPath,
            'filename' => 'thumb_' . $file->filename,
            'mime_type' => $file->mime_type,
            'size' => Storage::disk($file->disk)->size($thumbnailPath),
            'metadata' => [
                'width' => $image->width(),
                'height' => $image->height(),
                'original' => $file->path
            ]
        ]);
    }
}

class MediaValidator
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Validate uploaded file
     *
     * @param UploadedFile $file
     * @return bool
     * @throws MediaException
     */
    public function validate(UploadedFile $file): bool
    {
        // Check file size
        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException("File size exceeds maximum allowed size");
        }

        // Check mime type
        if (!in_array($file->getMimeType(), $this->config['allowed_types'])) {
            throw new MediaException("File type not allowed");
        }

        // Validate image dimensions if applicable
        if (Str::startsWith($file->getMimeType(), 'image/')) {
            $this->validateImage($file);
        }

        return true;
    }

    /**
     * Validate image file
     *
     * @param UploadedFile $file
     * @throws MediaException
     */
    protected function validateImage(UploadedFile $file): void
    {
        $image = Image::make($file);

        // Check minimum dimensions
        if (isset($this->config['min_width']) && $image->width() < $this->config['min_width']) {
            throw new MediaException("Image width below minimum requirement");
        }

        if (isset($this->config['min_height']) && $image->height() < $this->config['min_height']) {
            throw new MediaException("Image height below minimum requirement");
        }

        // Check for malicious content
        if ($this->detectMaliciousContent($file)) {
            throw new MediaException("File appears to be malicious");
        }
    }

    /**
     * Detect malicious content
     *
     * @param UploadedFile $file
     * @return bool
     */
    protected function detectMaliciousContent(UploadedFile $file): bool
    {
        // Implement malicious content detection
        // (e.g., check for embedded PHP code, suspicious metadata)
        return false;
    }
}

class MediaFile
{
    public string $path;
    public string $filename;
    public string $mime_type;
    public int $size;
    public array $metadata;
    public string $disk;

    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Get public URL
     *
     * @return string
     */
    public function getUrl(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Get file extension
     *
     * @return string
     */
    public function getExtension(): string
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION);
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Media\MediaManager;
use App\Core\Template\Media\MediaProcessor;
use App\Core\Template\Media\MediaValidator;
use App\Core\Template\Media\MediaStorage;

class MediaServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(MediaManager::class, function ($app) {
            $config = config('media');
            
            return new MediaManager(
                new MediaStorage($config),
                new MediaProcessor($config),
                new MediaValidator($config),
                $config
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $blade = $this->app['blade.compiler'];

        // Add Blade directive for media URLs
        $blade->directive('media', function ($expression) {
            return "<?php echo app(MediaManager::class)->get($expression)->getUrl(); ?>";
        });
    }
}
