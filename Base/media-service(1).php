<?php

namespace App\Core\Services;

use App\Core\Models\Media;
use App\Core\Services\Contracts\MediaServiceInterface;
use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use App\Core\Exceptions\MediaNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService implements MediaServiceInterface
{
    public function __construct(
        private MediaRepositoryInterface $repository,
        private string $diskName = 'public'
    ) {}

    public function upload(UploadedFile $file, ?string $type = null): Media
    {
        $path = $file->store('media/' . ($type ?? 'general'), $this->diskName);
        
        $data = [
            'name' => $file->getClientOriginalName(),
            'type' => $type ?? $this->determineType($file),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $this->generateMetadata($file)
        ];

        return $this->repository->store($data);
    }

    public function getById(int $id): ?Media
    {
        return $this->repository->findById($id);
    }

    public function deleteById(int $id): bool
    {
        $media = $this->repository->findById($id);
        Storage::disk($this->diskName)->delete($media->path);
        return $this->repository->delete($id);
    }

    public function getAllByType(string $type): Collection
    {
        return $this->repository->findByType($type);
    }

    public function updateMetadata(int $id, array $metadata): ?Media
    {
        return $this->repository->update($id, ['metadata' => $metadata]);
    }

    private function determineType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        
        return match (true) {
            Str::startsWith($mime, 'image/') => 'image',
            Str::startsWith($mime, 'video/') => 'video',
            Str::startsWith($mime, 'audio/') => 'audio',
            default => 'document'
        };
    }

    private function generateMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
        ];

        if (Str::startsWith($file->getMimeType(), 'image/')) {
            $dimensions = getimagesize($file->getPathname());
            if ($dimensions) {
                $metadata['width'] = $dimensions[0];
                $metadata['height'] = $dimensions[1];
            }
        }

        return $metadata;
    }
}
