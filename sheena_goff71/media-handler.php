<?php

namespace App\Core\Media\Services;

use App\Core\Media\Models\Media;
use App\Core\Media\Repositories\MediaRepository;
use App\Core\Media\Services\Processors\{
    ImageProcessor,
    VideoProcessor,
    DocumentProcessor
};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB};

class MediaHandlerService
{
    public function __construct(
        private MediaRepository $repository,
        private ImageProcessor $imageProcessor,
        private VideoProcessor $videoProcessor,
        private DocumentProcessor $documentProcessor,
        private MediaValidator $validator
    ) {}

    public function handleUpload(UploadedFile $file, array $options = []): Media
    {
        $this->validator->validateUpload($file, $options);

        return DB::transaction(function () use ($file, $options) {
            $processedFile = $this->processFile($file, $options);
            
            $media = $this->repository->create([
                'name' => $options['name'] ?? $file->getClientOriginalName(),
                'file_name' => $processedFile['file_name'],
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'disk' => $options['disk'] ?? config('media.default_disk'),
                'path' => $processedFile['path'],
                'metadata' => array_merge(
                    $processedFile['metadata'] ?? [],
                    $options['metadata'] ?? []
                )
            ]);

            if (!empty($processedFile['variants'])) {
                $this->storeVariants($media, $processedFile['variants']);
            }

            return $media;
        });
    }

    public function handleUpdate(Media $media, array $data): Media
    {
        $this->validator->validateUpdate($media, $data);

        return DB::transaction(function () use ($media, $data) {
            if (isset($data['file'])) {
                $this->deleteFile($media);
                $processedFile = $this->processFile($data['file'], $data);
                
                $data = array_merge($data, [
                    'file_name' => $processedFile['file_name'],
                    'mime_type' => $data['file']->getMimeType(),
                    'size' => $data['file']->getSize(),
                    'path' => $processedFile['path'],
                    'metadata' => array_merge(
                        $processedFile['metadata'] ?? [],
                        $data['metadata'] ?? []
                    )
                ]);

                if (!empty($processedFile['variants'])) {
                    $this->updateVariants($media, $processedFile['variants']);
                }
            }

            return $this->repository->update($media, $data);
        });
    }

    public function handleDelete(Media $media): bool
    {
        $this->validator->validateDelete($media);

        return DB::transaction(function () use ($media) {
            $this->deleteFile($media);
            $this->deleteVariants($media);
            return $this->repository->delete($media);
        });
    }

    protected function processFile(UploadedFile $file, array $options = []): array
    {
        return match($this->getFileType($file)) {
            'image' => $this->imageProcessor->process($file, $options),
            'video' => $this->videoProcessor->process($file, $options),
            'document' => $this->documentProcessor->process($file, $options),
            default => throw new \InvalidArgumentException('Unsupported file type')
        };
    }

    protected function getFileType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        
        return 'document';
    }

    protected function storeVariants(Media $media, array $variants): void
    {
        foreach ($variants as $type => $variant) {
            $media->variants()->create([
                'type' => $type,
                'file_name' => $variant['file_name'],
                'path' => $variant['path'],
                'mime_type' => $variant['mime_type'] ?? $media->mime_type,
                'size' => $variant['size'] ?? 0,
                'metadata' => $variant['metadata'] ?? []
            ]);
        }
    }

    protected function updateVariants(Media $media, array $variants): void
    {
        $this->deleteVariants($media);
        $this->storeVariants($media, $variants);
    }

    protected function deleteFile(Media $media): void
    {
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }
    }

    protected function deleteVariants(Media $media): void
    {
        foreach ($media->variants as $variant) {
            if (Storage::disk($media->disk)->exists($variant->path)) {
                Storage::disk($media->disk)->delete($variant->path);
            }
        }
        
        $media->variants()->delete();
    }
}
