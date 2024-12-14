<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaRepository implements MediaRepositoryInterface
{
    protected Media $model;

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Media
    {
        return $this->model->find($id);
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['mime_type'])) {
            $query->where('mime_type', 'LIKE', $filters['mime_type'] . '%');
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function create(array $data): Media
    {
        DB::beginTransaction();
        try {
            $media = $this->model->create($data);
            
            if (!empty($data['meta'])) {
                $media->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Media
    {
        DB::beginTransaction();
        try {
            $media = $this->model->findOrFail($id);
            $media->update($data);
            
            if (isset($data['meta'])) {
                $media->meta()->delete();
                $media->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $media = $this->model->findOrFail($id);
            
            // Delete physical file
            Storage::delete($media->path);
            
            // Delete variants if they exist
            foreach ($media->variants as $variant) {
                Storage::delete($variant->path);
                $variant->delete();
            }
            
            $media->meta()->delete();
            $media->delete();
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByType(string $type): Collection
    {
        return $this->model->where('type', $type)->get();
    }

    public function getByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function getByMimeType(string $mimeType): Collection
    {
        return $this->model->where('mime_type', 'LIKE', $mimeType . '%')->get();
    }

    public function createFromUpload(array $fileData): Media
    {
        DB::beginTransaction();
        try {
            $path = Storage::putFile('media', $fileData['file']);
            
            $media = $this->create([
                'name' => $fileData['name'] ?? $fileData['file']->getClientOriginalName(),
                'path' => $path,
                'type' => $fileData['type'] ?? 'file',
                'mime_type' => $fileData['file']->getMimeType(),
                'size' => $fileData['file']->getSize(),
                'user_id' => auth()->id(),
                'meta' => $fileData['meta'] ?? []
            ]);
            
            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($path)) {
                Storage::delete($path);
            }
            throw $e;
        }
    }

    public function getVariants(int $mediaId): Collection
    {
        return $this->model->findOrFail($mediaId)->variants;
    }

    public function attachToContent(int $mediaId, int $contentId, string $type = 'attachment'): bool
    {
        return DB::table('content_media')->insert([
            'content_id' => $contentId,
            'media_id' => $mediaId,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function detachFromContent(int $mediaId, int $contentId): bool
    {
        return (bool) DB::table('content_media')
            ->where('content_id', $contentId)
            ->where('media_id', $mediaId)
            ->delete();
    }
}
