<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\MediaManagerInterface;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private FileProcessor $processor;
    private ImageOptimizer $optimizer;
    private MetadataExtractor $metadata;
    private ValidationService $validator;
    private CacheManager $cache;
    
    public function __construct(
        SecurityManager $security,
        FileProcessor $processor,
        ImageOptimizer $optimizer,
        MetadataExtractor $metadata,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->processor = $processor;
        $this->optimizer = $optimizer;
        $this->metadata = $metadata;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function processUpload(UploadedFile $file, SecurityContext $context): MediaResult
    {
        return $this->security->executeCriticalOperation(
            new ProcessMediaOperation(
                $file,
                $this->processor,
                $this->optimizer,
                $this->metadata,
                $this->validator
            ),
            $context
        );
    }

    public function createVariant(int $mediaId, array $options, SecurityContext $context): MediaVariant
    {
        return $this->security->executeCriticalOperation(
            new CreateVariantOperation(
                $mediaId,
                $options,
                $this->processor,
                $this->optimizer,
                $this->cache
            ),
            $context
        );
    }

    public function delete(int $mediaId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteMediaOperation($mediaId, $this->cache),
            $context
        );
    }

    public function getSecureUrl(int $mediaId, array $options = []): string
    {
        $key = $this->generateSecureKey($mediaId, $options);
        return route('media.serve', ['key' => $key]);
    }

    private function generateSecureKey(int $mediaId, array $options): string
    {
        $data = json_encode([
            'id' => $mediaId,
            'options' => $options,
            'timestamp' => time()
        ]);
        
        return hash_hmac('sha256', $data, config('app.key'));
    }
}

class ProcessMediaOperation implements CriticalOperation
{
    private UploadedFile $file;
    private FileProcessor $processor;
    private ImageOptimizer $optimizer;
    private MetadataExtractor $metadata;
    private ValidationService $validator;

    public function __construct(
        UploadedFile $file,
        FileProcessor $processor,
        ImageOptimizer $optimizer,
        MetadataExtractor $metadata,
        ValidationService $validator
    ) {
        $this->file = $file;
        $this->processor = $processor;
        $this->optimizer = $optimizer;
        $this->metadata = $metadata;
        $this->validator = $validator;
    }

    public function execute(): MediaResult
    {
        $this->validator->validateFile($this->file);
        $metadata = $this->metadata->extract($this->file);

        DB::beginTransaction();
        
        try {
            $path = $this->processor->store($this->file);
            
            if ($this->isImage($this->file)) {
                $this->optimizer->optimize($path);
            }
            
            $media = Media::create([
                'path' => $path,
                'filename' => $this->file->getClientOriginalName(),
                'mime_type' => $this->file->getMimeType(),
                'size' => $this->file->getSize(),
                'metadata' => $metadata
            ]);
            
            DB::commit();
            return new MediaResult($media);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->processor->cleanup($path);
            throw $e;
        }
    }

    private function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }

    public function getValidationRules(): array
    {
        return [
            'file' => 'required|file|max:' . config('media.max_size')
        ];
    }

    public function getData(): array
    {
        return ['file' => $this->file];
    }

    public function getRequiredPermissions(): array
    {
        return ['media:upload'];
    }
}

class FileProcessor
{
    private Storage $storage;
    private string $disk;

    public function store(UploadedFile $file): string
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = $file->getClientOriginalExtension();
        $path = $this->generatePath($hash, $extension);
        
        Storage::disk($this->disk)->put(
            $path,
            file_get_contents($file->getRealPath())
        );
        
        return $path;
    }

    public function cleanup(string $path): void
    {
        if (Storage::disk($this->disk)->exists($path)) {
            Storage::disk($this->disk)->delete($path);
        }
    }

    private function generatePath(string $hash, string $extension): string
    {
        return implode('/', [
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            $hash . '.' . $extension
        ]);
    }
}

class ImageOptimizer
{
    private array $config;

    public function optimize(string $path): void
    {
        if (!$this->isOptimizable($path)) {
            return;
        }

        $image = Image::make(Storage::disk('media')->path($path));
        
        $image->orientate()
              ->resize(
                  $this->config['max_width'],
                  $this->config['max_height'],
                  function ($constraint) {
                      $constraint->aspectRatio();
                      $constraint->upsize();
                  }
              )
              ->save(null, $this->config['quality']);
    }

    private function isOptimizable(string $path): bool
    {
        $mime = Storage::disk('media')->mimeType($path);
        return in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ]);
    }
}
