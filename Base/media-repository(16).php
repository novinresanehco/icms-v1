<?php

namespace App\Core\Repositories;

use App\Models\Media;
use Illuminate\Support\Collection;

class MediaRepository extends AdvancedRepository
{
    protected $model = Media::class;

    public function store(array $file, string $model = null, int $modelId = null): Media
    {
        return $this->executeTransaction(function() use ($file, $model, $modelId) {
            $media = $this->create([
                'filename' => $file['name'],
                'path' => $file['path'],
                'mime_type' => $file['mime_type'],
                'size' => $file['size'],
                'disk' => $file['disk'],
                'mediable_type' => $model,
                'mediable_id' => $modelId,
                'metadata' => $file['metadata'] ?? [],
                'created_by' => auth()->id()
            ]);

            if ($model && $modelId) {
                $this->invalidateCache('getMedia', $model, $modelId);
            }

            return $media;
        });
    }

    public function getMedia(string $model, int $modelId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId) {
            return $this->model
                ->where('mediable_type', $model)
                ->where('mediable_id', $modelId)
                ->orderBy('created_at', 'desc')
                ->get();
        }, $model, $modelId);
    }

    public function delete(int $id): bool
    {
        return $this->executeTransaction(function() use ($id) {
            $media = $this->findOrFail($id);
            
            // Delete physical file
            storage()->disk($media->disk)->delete($media->path);
            
            // Delete record
            $deleted = $media->delete();

            if ($media->mediable_type && $media->mediable_id) {
                $this->invalidateCache('getMedia', $media->mediable_type, $media->mediable_id);
            }

            return $deleted;
        });
    }

    public function updateMetadata(int $id, array $metadata): Media
    {
        return $this->executeTransaction(function() use ($id, $metadata) {
            $media = $this->findOrFail($id);
            $media->metadata = array_merge($media->metadata, $metadata);
            $media->save();

            if ($media->mediable_type && $media->mediable_id) {
                $this->invalidateCache('getMedia', $media->mediable_type, $media->mediable_id);
            }

            return $media;
        });
    }

    public function search(array $criteria): Collection
    {
        return $this->executeQuery(function() use ($criteria) {
            $query = $this->model->newQuery();

            if (isset($criteria['mime_type'])) {
                $query->where('mime_type', 'LIKE', $criteria['mime_type'] . '%');
            }

            if (isset($criteria['filename'])) {
                $query->where('filename', 'LIKE', '%' . $criteria['filename'] . '%');
            }

            if (isset($criteria['created_by'])) {
                $query->where('created_by', $criteria['created_by']);
            }

            if (isset($criteria['date_range'])) {
                $query->whereBetween('created_at', [
                    $criteria['date_range']['start'],
                    $criteria['date_range']['end']
                ]);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
    }
}
