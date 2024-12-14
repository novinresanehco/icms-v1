<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, DB};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;

class MediaManager implements MediaManagerInterface
{
    private MediaRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private FileProcessor $processor;
    private StorageManager $storage;

    public function upload(UploadedFile $file, SecurityContext $context): Media
    {
        return $this->executeSecureOperation(function() use ($file) {
            $this->validator->validateFile($file, $this->getAllowedTypes());
            
            DB::beginTransaction();
            try {
                $processed = $this->processor->process($file);
                $path = $this->storage->store($processed);
                
                $media = $this->repository->create([
                    'path' => $path,
                    'type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'metadata' => $processed->getMetadata()
                ]);
                
                $this->processor->generateThumbnails($media);
                DB::commit();
                
                return $media;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->storage->cleanup($path);
                throw $e;
            }
        }, $context, 'media.upload');
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $media = $this->repository->findOrFail($id);
                
                $this->storage->delete($media->path);
                $this->storage->deleteThumbnails($media);
                $this->repository->delete($media);
                
                DB::commit();
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context, 'media.delete');
    }

    public function attachToContent(Content $content, array $mediaIds): void
    {
        $media = $this->repository->findMany($mediaIds);
        $content->attachMedia($media);
    }

    public function optimize(Media $media): void
    {
        $this->processor->optimize($media);
        $this->storage->replace($media->path, $media->getOptimizedPath());
    }

    private function executeSecureOperation(callable $operation, SecurityContext $context, string $permission): mixed
    {
        return $this->security->executeSecureOperation(
            new MediaOperation($operation, $permission),
            $context
        );
    }

    private function getAllowedTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
            'text/plain'
        ];
    }
}

class FileProcessor
{
    private array $thumbnailSizes = [
        'small' => [200, 200],
        'medium' => [800, 800]
    ];

    public function process(UploadedFile $file): ProcessedFile
    {
        $processed = new ProcessedFile($file);
        
        if ($this->isImage($file)) {
            $processed->optimize();
            $processed->stripMetadata();
            $processed->sanitizeFilename();
        }
        
        return $processed;
    }

    public function generateThumbnails(Media $media): void
    {
        if (!$this->isImage($media)) {
            return;
        }

        foreach ($this->thumbnailSizes as $size => [$width, $height]) {
            $thumbnail = $this->createThumbnail($media, $width, $height);
            $media->addThumbnail($size, $thumbnail);
        }
    }

    public function optimize(Media $media): void
    {
        if (!$this->isImage($media)) {
            return;
        }

        $image = Image::make($media->getPath());
        $image->optimize();
        $image->save($media->getOptimizedPath());
    }

    private function createThumbnail(Media $media, int $width, int $height): string
    {
        $image = Image::make($media->getPath());
        $image->fit($width, $height);
        
        $path = $this->getThumbnailPath($media, $width, $height);
        $image->save($path);
        
        return $path;
    }

    private function isImage(mixed $file): bool
    {
        $mimeType = $file instanceof UploadedFile ? 
            $file->getMimeType() : 
            $file->type;
            
        return str_starts_with($mimeType, 'image/');
    }
}

class StorageManager
{
    private Storage $storage;
    private string $disk;

    public function store(ProcessedFile $file): string
    {
        $path = $this->generatePath($file);
        $this->storage->disk($this->disk)->put($path, $file->getContents());
        
        return $path;
    }

    public function delete(string $path): void
    {
        $this->storage->disk($this->disk)->delete($path);
    }

    public function deleteThumbnails(Media $media): void
    {
        foreach ($media->getThumbnails() as $thumbnail) {
            $this->delete($thumbnail);
        }
    }

    public function replace(string $oldPath, string $newPath): void
    {
        $this->storage->disk($this->disk)->delete($oldPath);
        $this->storage->disk($this->disk)->move($newPath, $oldPath);
    }

    private function generatePath(ProcessedFile $file): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            date('Y/m'),
            md5(uniqid()),
            $file->getSanitizedName(),
            $file->getExtension()
        );
    }
}

interface MediaManagerInterface
{
    public function upload(UploadedFile $file, SecurityContext $context): Media;
    public function delete(int $id, SecurityContext $context): bool;
    public function attachToContent(Content $content, array $mediaIds): void;
    public function optimize(Media $media): void;
}
