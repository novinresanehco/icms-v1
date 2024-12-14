<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaRepository implements MediaRepositoryInterface
{
    protected $model;

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->findOrFail($id);
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model
            ->when(isset($filters['type']), function ($query) use ($filters) {
                return $query->where('type', $filters['type']);
            })
            ->when(isset($filters['search']), function ($query) use ($filters) {
                return $query->where(function ($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('alt_text', 'like', "%{$filters['search']}%");
                });
            })
            ->when(isset($filters['date_from']), function ($query) use ($filters) {
                return $query->where('created_at', '>=', $filters['date_from']);
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters) {
                return $query->where('created_at', '<=', $filters['date_to']);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            if (isset($data['file'])) {
                $path = $data['file']->store('media', 'public');
                $data['path'] = $path;
                $data['mime_type'] = $data['file']->getMimeType();
                $data['size'] = $data['file']->getSize();
                unset($data['file']);
            }

            return $this->model->create($data);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $media = $this->find($id);

            if (isset($data['file'])) {
                // Delete old file
                if ($media->path) {
                    Storage::disk('public')->delete($media->path);
                }

                $path = $data['file']->store('media', 'public');
                $data['path'] = $path;
                $data['mime_type'] = $data['file']->getMimeType();
                $data['size'] = $data['file']->getSize();
                unset($data['file']);
            }

            $media->update($data);
            return $media->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $media = $this->find($id);
            
            // Delete file from storage
            if ($media->path) {
                Storage::disk('public')->delete($media->path);
            }
            
            // Delete thumbnails if they exist
            if ($media->thumbnails) {
                foreach ($media->thumbnails as $thumbnail) {
                    Storage::disk('public')->delete($thumbnail);
                }
            }
            
            // Detach from all content
            $media->contents()->detach();
            
            return $media->delete();
        });
    }

    public function getByContent(int $contentId): Collection
    {
        return $this->model
            ->whereHas('contents', function ($query) use ($contentId) {
                $query->where('content_id', $contentId);
            })
            ->with(['contents' => function ($query) use ($contentId) {
                $query->where('content_id', $contentId);
            }])
            ->get();
    }

    public function attachToContent(int $mediaId, int $contentId, array $attributes = [])
    {
        return DB::transaction(function () use ($mediaId, $contentId, $attributes) {
            $media = $this->find($mediaId);
            $media->contents()->attach($contentId, $attributes);
            return $media->fresh(['contents']);
        });
    }

    public function detachFromContent(int $mediaId, int $contentId)
    {
        return DB::transaction(function () use ($mediaId, $contentId) {
            $media = $this->find($mediaId);
            $media->contents()->detach($contentId);
            return $media->fresh();
        });
    }

    public function updateContentAssociation(int $mediaId, int $contentId, array $attributes)
    {
        return DB::transaction(function () use ($mediaId, $contentId, $attributes) {
            $media = $this->find($mediaId);
            $media->contents()->updateExistingPivot($contentId, $attributes);
            return $media->fresh(['contents']);
        });
    }
}
