<?php

namespace App\Repositories;

use App\Models\Media;
use App\Models\MediaVersion;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Media();
    }

    public function findByPath(string $path): ?Media
    {
        return $this->model->where('path', $path)->first();
    }

    public function storeFile(UploadedFile $file, array $metadata = []): Media
    {
        $path = $file->store('media', 'public');
        
        $media = $this->model->create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $metadata,
            'type' => $this->determineType($file->getMimeType())
        ]);
        
        if ($this->isImage($media->mime_type)) {
            $this->processImage($media, $file);
        }
        
        $this->createVersion($media);
        
        return $media;
    }

    public function updateMetadata(int $id, array $metadata): bool
    {
        $media = $this->model->findOrFail($id);
        
        $updated = $media->update([
            'metadata' => array_merge($media->metadata ?? [], $metadata)
        ]);
        
        if ($updated) {
            $this->createVersion($media);
        }
        
        return $updated;
    }

    public function getByMimeType(string $mimeType): Collection
    {
        return $this->model->where('mime_type', $mimeType)->get();
    }

    public function getByType(string $type): Collection
    {
        return $this->model->where('type', $type)->get();
    }

    public function getMediaVersions(int $id): Collection
    {
        return MediaVersion::where('media_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function duplicateMedia(int $id): Media
    {
        $original = $this->model->findOrFail($id);
        $newPath = $this->generateUniquePath($original->path);
        
        Storage::disk('public')->copy($original->path, $newPath);
        
        $newMedia = $this->model->create([
            'name' => $this->generateUniqueName($original->name),
            'path' => $newPath,
            'mime_type' => $original->mime_type,
            'size' => $original->size,
            'metadata' => $original->metadata,
            'type' => $original->type
        ]);
        
        $this->createVersion($newMedia);
        
        return $newMedia;
    }

    public function moveMedia(int $id, string $newPath): bool
    {
        $media = $this->model->findOrFail($id);
        
        if (Storage::disk('public')->move($media->path, $newPath)) {
            return $media->update(['path' => $newPath]);
        }
        
        return false;
    }

    public function addToCollection(int $mediaId, int $collectionId): bool
    {
        $media = $this->model->findOrFail($mediaId);
        return $media->collections()->attach($collectionId);
    }

    public function removeFromCollection(int $mediaId, int $collectionId): bool
    {
        $media = $this->model->findOrFail($mediaId);
        return $media->collections()->detach($collectionId);
    }

    public function optimizeMedia(int $id): bool
    {
        $media = $this->model->findOrFail($id);
        
        if (!$this->isImage($media->mime_type)) {
            return false;
        }
        
        $path = Storage::disk('public')->path($media->path);
        $image = Image::make($path);
        
        $image->optimize();
        
        return $image->save($path, 80);
    }

    protected function createVersion(Media $media): void
    {
        MediaVersion::create([
            'media_id' => $media->id,
            'path' => $media->path,
            'metadata' => $media->metadata,
            'created_by' => auth()->id()
        ]);
    }

    protected function determineType(string $mimeType): string
    {
        if (strpos($mimeType, 'image/') === 0) return 'image';
        if (strpos($mimeType, 'video/') === 0) return 'video';
        if (strpos($mimeType, 'audio/') === 0) return 'audio';
        if (strpos($mimeType, 'application/pdf') === 0) return 'document';
        return 'other';
    }

    protected function isImage(string $mimeType): bool
    {
        return strpos($mimeType, 'image/') === 0;
    }

    protected function processImage(Media $media, UploadedFile $file): void
    {
        $image = Image::make($file->path());
        
        $media->update([
            'metadata' => array_merge($media->metadata ?? [], [
                'dimensions' => [
                    'width' => $image->width(),
                    'height' => $image->height()
                ]
            ])
        ]);
    }

    protected function generateUniquePath(string $originalPath): string
    {
        $info = pathinfo($originalPath);
        $counter = 1;
        $newPath = $originalPath;
        
        while (Storage::disk('public')->exists($newPath)) {
            $newPath = $info['dirname'] . '/' . $info['filename'] . '_' . $counter . '.' . $info['extension'];
            $counter++;
        }
        
        return $newPath;
    }

    protected function generateUniqueName(string $originalName): string
    {
        $info = pathinfo($originalName);
        return $info['filename'] . '_copy.' . $info['extension'];
    }
}
