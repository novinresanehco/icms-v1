<?php

namespace App\Core\Repositories;

use App\Core\Models\Media;
use App\Core\Events\{MediaCreated, MediaUpdated, MediaDeleted};
use App\Core\Exceptions\MediaException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event, Storage};

class MediaRepository extends Repository
{
    protected array $with = ['creator', 'folder'];
    protected array $withCount = ['usages'];
    protected bool $enableCache = true;
    protected int $cacheDuration = 3600;

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function findByPath(string $path): ?Model
    {
        return $this->remember(fn() => 
            $this->query()
                ->where('path', $path)
                ->first()
        );
    }

    public function createFromUpload(array $attributes, $file): Model 
    {
        return DB::transaction(function() use ($attributes, $file) {
            $path = Storage::putFile('media', $file);
            
            $attributes['path'] = $path;
            $attributes['size'] = $file->getSize();
            $attributes['mime_type'] = $file->getMimeType();
            $attributes['original_name'] = $file->getClientOriginalName();
            
            $media = $this->create($attributes);
            
            $this->processMediaFile($media);
            
            return $media;
        });
    }

    public function getByFolder(?int $folderId): Collection
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('folder_id', $folderId)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function moveToFolder(Model $media, ?int $folderId): bool
    {
        return $this->update($media, [
            'folder_id' => $folderId
        ]);
    }

    public function deleteWithFile(Model $media): bool
    {
        return DB::transaction(function() use ($media) {
            if ($media->usages_count > 0) {
                throw new MediaException('Cannot delete media that is in use');
            }

            Storage::delete($media->path);
            Storage::delete($media->getThumbnailPaths());
            
            return $this->delete($media);
        });
    }

    protected function processMediaFile(Model $media): void
    {
        if (str_starts_with($media->mime_type, 'image/')) {
            $this->generateThumbnails($media);
            $this->extractImageMetadata($media);
        }
    }

    protected function generateThumbnails(Model $media): void
    {
        // Image processing implementation
    }

    protected function extractImageMetadata(Model $media): void
    {
        // Metadata extraction implementation
    }
}

class MediaFolderRepository extends Repository
{
    protected array $with = ['parent'];
    protected array $withCount = ['media', 'children'];

    public function getTree(): Collection
    {
        return $this->remember(fn() =>
            $this->query()
                ->whereNull('parent_id')
                ->with('children')
                ->get()
        );
    }

    public function moveTo(Model $folder, ?int $parentId): bool
    {
        if ($this->wouldCreateCycle($folder, $parentId)) {
            throw new MediaException('Moving folder would create cycle');
        }

        return $this->update($folder, [
            'parent_id' => $parentId
        ]);
    }

    protected function wouldCreateCycle(Model $folder, ?int $parentId): bool
    {
        if (!$parentId || $folder->id === $parentId) {
            return true;
        }

        $parent = $this->find($parentId);
        while ($parent && $parent->parent_id) {
            if ($parent->parent_id === $folder->id) {
                return true;
            }
            $parent = $parent->parent;
        }

        return false;
    }
}

class MediaUsageRepository extends Repository
{
    public function trackUsage(Model $media, Model $usedBy): Model
    {
        return DB::transaction(function() use ($media, $usedBy) {
            return $this->create([
                'media_id' => $media->id,
                'usable_type' => get_class($usedBy),
                'usable_id' => $usedBy->id
            ]);
        });
    }

    public function removeUsage(Model $media, Model $usedBy): bool
    {
        return DB::transaction(function() use ($media, $usedBy) {
            return $this->query()
                ->where('media_id', $media->id)
                ->where('usable_type', get_class($usedBy))
                ->where('usable_id', $usedBy->id)
                ->delete();
        });
    }

    public function getUsages(Model $media): Collection
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('media_id', $media->id)
                ->with('usable')
                ->get()
        );
    }
}
