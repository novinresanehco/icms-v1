<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, Cache, DB};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{MediaManagerInterface, ValidationInterface};

class MediaManager implements MediaManagerInterface
{
    private CoreSecurityManager $security;
    private ValidationInterface $validator;
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private int $maxFileSize = 10485760; // 10MB
    
    public function store(UploadedFile $file): MediaFile
    {
        return $this->security->executeCriticalOperation(
            new MediaOperation('store', ['file' => $file], function() use ($file) {
                $this->validateFile($file);
                
                DB::beginTransaction();
                try {
                    $optimizedFile = $this->optimizeFile($file);
                    $path = $this->storeSecurely($optimizedFile);
                    
                    $media = new MediaFile([
                        'path' => $path,
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'hash' => hash_file('sha256', $file->getRealPath())
                    ]);
                    
                    $media->save();
                    $this->generateThumbnails($media);
                    $this->updateCache($media);
                    
                    DB::commit();
                    return $media;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    Storage::delete($path);
                    throw $e;
                }
            })
        );
    }

    public function retrieve(int $id): MediaFile
    {
        return Cache::remember(
            "media:$id",
            3600,
            fn() => MediaFile::findOrFail($id)
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new MediaOperation('delete', ['id' => $id], function() use ($id) {
                DB::beginTransaction();
                try {
                    $media = MediaFile::findOrFail($id);
                    
                    Storage::delete([
                        $media->path,
                        ...$this->getThumbnailPaths($media)
                    ]);
                    
                    $media->delete();
                    Cache::forget("media:$id");
                    
                    DB::commit();
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedTypes)) {
            throw new InvalidFileTypeException('Unsupported file type');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new FileSizeLimitException('File size exceeds limit');
        }

        if (!$this->isSecureFile($file)) {
            throw new SecurityException('File failed security check');
        }
    }

    private function optimizeFile(UploadedFile $file): UploadedFile
    {
        if (str_starts_with($file->getMimeType(), 'image/')) {
            return $this->optimizeImage($file);
        }
        
        return $file;
    }

    private function optimizeImage(UploadedFile $file): UploadedFile
    {
        $image = Image::make($file->getRealPath());
        
        $image->resize(2000, 2000, function($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        
        if ($file->getMimeType() === 'image/jpeg') {
            $image->save(null, 85); // 85% quality
        }
        
        return new UploadedFile(
            $image->basePath(),
            $file->getClientOriginalName(),
            $file->getMimeType()
        );
    }

    private function storeSecurely(UploadedFile $file): string
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = $file->getClientOriginalExtension();
        
        return Storage::putFileAs(
            'secure',
            $file,
            "$hash.$extension",
            'private'
        );
    }

    private function generateThumbnails(MediaFile $media): void
    {
        if (!str_starts_with($media->mime_type, 'image/')) {
            return;
        }

        $image = Image::make(Storage::path($media->path));
        
        foreach ([200, 400, 800] as $size) {
            $thumbnail = clone $image;
            $thumbnail->resize($size, $size, function($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            
            $path = "thumbnails/{$size}/{$media->id}.jpg";
            Storage::put($path, $thumbnail->encode('jpg', 85));
        }
    }

    private function getThumbnailPaths(MediaFile $media): array
    {
        if (!str_starts_with($media->mime_type, 'image/')) {
            return [];
        }

        return array_map(
            fn($size) => "thumbnails/{$size}/{$media->id}.jpg",
            [200, 400, 800]
        );
    }

    private function isSecureFile(UploadedFile $file): bool
    {
        // Check for malicious content
        $content = file_get_contents($file->getRealPath());
        
        return !preg_match('/<script|<php|<\?php/i', $content) &&
               !preg_match('/\x00/', $content) &&
               mime_content_type($file->getRealPath()) === $file->getMimeType();
    }

    private function updateCache(MediaFile $media): void
    {
        Cache::put("media:{$media->id}", $media, 3600);
    }
}

class MediaOperation implements CriticalOperation
{
    private string $type;
    private array $data;
    private \Closure $operation;
    
    public function __construct(string $type, array $data, \Closure $operation)
    {
        $this->type = $type;
        $this->data = $data;
        $this->operation = $operation;
    }

    public function execute(): mixed
    {
        return ($this->operation)();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
