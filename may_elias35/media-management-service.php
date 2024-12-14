namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\MetricsCollector;
use App\Core\Cache\CacheManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class MediaService
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private CacheManager $cache;
    private ImageManager $imageManager;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        CacheManager $cache,
        ImageManager $imageManager,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->cache = $cache;
        $this->imageManager = $imageManager;
        $this->config = $config;
    }

    public function handleUpload(UploadedFile $file, array $options, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($file, $options, $context) {
                $this->validateFile($file);
                $this->checkQuota($context['user_id']);
                
                $fileInfo = $this->processFile($file, $options);
                $mediaRecord = $this->createMediaRecord($fileInfo, $context);
                
                $this->generateVariants($fileInfo, $options);
                $this->updateQuota($context['user_id'], $fileInfo['size']);
                
                $this->metrics->recordUpload($fileInfo['size']);
                
                return $mediaRecord;
            },
            $context
        );
    }

    public function getMedia(int $id, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                $media = $this->findMedia($id);
                
                if (!$media) {
                    throw new MediaNotFoundException("Media not found: {$id}");
                }

                return $media;
            },
            $context
        );
    }

    public function generateUrl(int $id, array $options, array $context): string
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $options) {
                $media = $this->findMedia($id);
                
                if (!$media) {
                    throw new MediaNotFoundException("Media not found: {$id}");
                }

                return $this->generateSecureUrl($media, $options);
            },
            $context
        );
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), $this->config['allowed_types'])) {
            throw new MediaException('Unsupported file type');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File too large');
        }

        if (!$this->validator->validateFile($file)) {
            throw new MediaException('File validation failed');
        }
    }

    protected function processFile(UploadedFile $file, array $options): array
    {
        $filename = $this->generateSecureFilename($file);
        $path = $this->getStoragePath($filename);
        
        $fileInfo = [
            'original_name' => $file->getClientOriginalName(),
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'hash' => hash_file('sha256', $file->getRealPath())
        ];

        if ($this->isImage($file)) {
            $fileInfo['metadata'] = $this->extractImageMetadata($file);
            $this->optimizeImage($file, $options);
        }

        $this->moveFile($file, $path);
        
        return $fileInfo;
    }

    protected function generateVariants(array $fileInfo, array $options): void
    {
        if (!$this->isImage($fileInfo)) {
            return;
        }

        foreach ($this->config['image_variants'] as $variant => $specs) {
            if ($this->shouldGenerateVariant($variant, $options)) {
                $this->generateImageVariant($fileInfo, $variant, $specs);
            }
        }
    }

    protected function generateImageVariant(array $fileInfo, string $variant, array $specs): void
    {
        $variantPath = $this->getVariantPath($fileInfo['path'], $variant);
        
        $image = $this->imageManager->make(Storage::path($fileInfo['path']));
        
        if (isset($specs['width'], $specs['height'])) {
            $image->fit($specs['width'], $specs['height']);
        }

        if (isset($specs['quality'])) {
            $image->quality($specs['quality']);
        }

        $image->save(Storage::path($variantPath));
    }

    protected function createMediaRecord(array $fileInfo, array $context): array
    {
        $record = array_merge($fileInfo, [
            'user_id' => $context['user_id'],
            'status' => 'active',
            'created_at' => now(),
            'variants' => $this->getGeneratedVariants($fileInfo['path'])
        ]);

        return DB::transaction(function() use ($record) {
            $id = DB::table('media')->insertGetId($record);
            return array_merge($record, ['id' => $id]);
        });
    }

    protected function generateSecureUrl(array $media, array $options): string
    {
        $path = $media['path'];
        
        if (isset($options['variant'])) {
            $path = $this->getVariantPath($path, $options['variant']);
        }

        $token = $this->generateSecureToken($path, $options);
        
        return route('media.serve', [
            'path' => $path,
            'token' => $token
        ]);
    }

    protected function generateSecureToken(string $path, array $options): string
    {
        $payload = [
            'path' => $path,
            'options' => $options,
            'exp' => time() + ($options['ttl'] ?? 3600)
        ];

        return hash_hmac('sha256', json_encode($payload), $this->config['secret_key']);
    }

    protected function checkQuota(int $userId): void
    {
        $used = $this->getUserQuotaUsage($userId);
        $limit = $this->getUserQuotaLimit($userId);
        
        if ($used >= $limit) {
            throw new QuotaExceededException('Storage quota exceeded');
        }
    }

    protected function updateQuota(int $userId, int $size): void
    {
        $key = "quota:usage:{$userId}";
        $this->cache->increment($key, $size);
    }

    protected function getUserQuotaUsage(int $userId): int
    {
        $key = "quota:usage:{$userId}";
        return (int) $this->cache->get($key, 0);
    }

    protected function getUserQuotaLimit(int $userId): int
    {
        return $this->config['default_quota'];
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        return hash('sha256', uniqid('', true)) . '.' . $file->getClientOriginalExtension();
    }

    protected function isImage(UploadedFile|array $file): bool
    {
        $mime = is_array($file) ? $file['mime_type'] : $file->getMimeType();
        return strpos($mime, 'image/') === 0;
    }

    protected function optimizeImage(UploadedFile $file, array $options): void
    {
        $image = $this->imageManager->make($file->getRealPath());
        
        if (isset($options['max_width'])) {
            $image->resize($options['max_width'], null, function($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        $image->save(null, $options['quality'] ?? 85);
    }
}
