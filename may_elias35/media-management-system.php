namespace App\Core\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private StorageManager $storage;
    private MediaRepository $repository;
    private ImageProcessor $processor;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        StorageManager $storage,
        MediaRepository $repository,
        ImageProcessor $processor,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->repository = $repository;
        $this->processor = $processor;
        $this->metrics = $metrics;
    }

    public function upload(UploadedFile $file, array $options = []): Media
    {
        return $this->security->executeSecureOperation(function() use ($file, $options) {
            $startTime = microtime(true);
            
            // Validate file
            $this->validateFile($file);
            
            // Process and store file
            $media = DB::transaction(function() use ($file, $options) {
                // Store original file
                $path = $this->storage->store($file);
                
                // Create media record
                $media = $this->repository->create([
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'path' => $path,
                    'disk' => $this->storage->getDefaultDisk(),
                    'metadata' => $this->extractMetadata($file)
                ]);
                
                // Generate thumbnails for images
                if ($this->isImage($file)) {
                    $this->generateThumbnails($media);
                }
                
                // Update cache
                $this->updateCache($media);
                
                return $media;
            });
            
            $this->metrics->recordUploadTime(microtime(true) - $startTime);
            
            return $media;
        }, ['action' => 'media.upload']);
    }

    public function process(Media $media): void
    {
        $this->security->executeSecureOperation(function() use ($media) {
            if ($this->isImage($media)) {
                $this->processor->optimize($media);
                $this->generateThumbnails($media);
            }
            
            $this->updateCache($media);
        }, ['action' => 'media.process', 'resource' => $media->id]);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            $media = $this->repository->findOrFail($id);
            
            DB::transaction(function() use ($media) {
                // Delete files
                $this->storage->delete($media->path);
                $this->deleteThumbnails($media);
                
                // Delete record
                $media->delete();
                
                // Clear cache
                $this->clearCache($media);
            });
            
            return true;
        }, ['action' => 'media.delete', 'resource' => $id]);
    }

    private function validateFile(UploadedFile $file): void
    {
        $validator = validator(['file' => $file], [
            'file' => 'required|file|mimes:jpeg,png,gif,pdf,doc,docx|max:10240'
        ]);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }

    private function generateThumbnails(Media $media): void
    {
        $sizes = config('media.thumbnail_sizes', [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [600, 600]
        ]);
        
        foreach ($sizes as $size => $dimensions) {
            $thumbnail = $this->processor->createThumbnail(
                $media,
                $dimensions[0],
                $dimensions[1]
            );
            
            $path = $this->storage->storeThumbnail($thumbnail, $media, $size);
            
            $media->thumbnails()->create([
                'size' => $size,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'path' => $path
            ]);
        }
    }

    private function deleteThumbnails(Media $media): void
    {
        foreach ($media->thumbnails as $thumbnail) {
            $this->storage->delete($thumbnail->path);
        }
        
        $media->thumbnails()->delete();
    }

    private function isImage($file): bool
    {
        $mimeType = $file instanceof UploadedFile 
            ? $file->getMimeType()
            : $file->mime_type;
            
        return strpos($mimeType, 'image/') === 0;
    }

    private function extractMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];
        
        if ($this->isImage($file)) {
            $imageInfo = getimagesize($file->path());
            $metadata['dimensions'] = [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            ];
        }
        
        return $metadata;
    }

    private function updateCache(Media $media): void
    {
        $key = "media.{$media->id}";
        $this->cache->put($key, $media, 3600);
        $this->cache->tags(['media'])->put("list", $this->repository->getList(), 3600);
    }

    private function clearCache(Media $media): void
    {
        $this->cache->forget("media.{$media->id}");
        $this->cache->tags(['media'])->flush();
    }
}

class ImageProcessor
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function optimize(Media $media): void
    {
        $image = Image::make($media->getPath());
        
        $image->orientate()
              ->optimize()
              ->save();
    }

    public function createThumbnail(
        Media $media,
        int $width,
        int $height
    ): UploadedFile {
        $image = Image::make($media->getPath());
        
        $image->fit($width, $height, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        
        $tempPath = tempnam(sys_get_temp_dir(), 'thumb');
        $image->save($tempPath);
        
        return new UploadedFile(
            $tempPath,
            $media->filename,
            $media->mime_type,
            null,
            true
        );
    }
}

class StorageManager
{
    private $disk;
    
    public function __construct(string $disk = 'media')
    {
        $this->disk = Storage::disk($disk);
    }
    
    public function store(UploadedFile $file): string
    {
        return $file->store('', ['disk' => $this->disk->getName()]);
    }
    
    public function storeThumbnail(
        UploadedFile $file,
        Media $media,
        string $size
    ): string {
        $path = "thumbnails/{$size}/" . $media->path;
        
        $this->disk->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );
        
        return $path;
    }
    
    public function delete(string $path): bool
    {
        return $this->disk->delete($path);
    }
    
    public function getDefaultDisk(): string
    {
        return $this->disk->getName();
    }
}
