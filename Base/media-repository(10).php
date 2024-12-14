<?php

namespace App\Core\Repositories;

use App\Core\Models\Media;
use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class MediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private Media $model
    ) {}

    public function findById(int $id): ?Media
    {
        return $this->model->find($id);
    }

    public function getForModel(string $modelType, int $modelId): Collection
    {
        return $this->model
            ->where('mediable_type', $modelType)
            ->where('mediable_id', $modelId)
            ->orderBy('order')
            ->get();
    }

    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->latest()
            ->paginate($perPage);
    }

    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->where('type', $type)
            ->latest()
            ->paginate($perPage);
    }

    public function store(array $data): Media
    {
        if (isset($data['file'])) {
            $path = $data['file']->store('media', 'public');
            $data['path'] = $path;
            $data['size'] = $data['file']->getSize();
            $data['mime_type'] = $data['file']->getMimeType();
            unset($data['file']);
        }

        return $this->model->create($data);
    }

    public function update(int $id, array $data): Media
    {
        $media = $this->model->findOrFail($id);

        if (isset($data['file'])) {
            // Delete old file
            if ($media->path) {
                Storage::disk('public')->delete($media->path);
            }

            $path = $data['file']->store('media', 'public');
            $data['path'] = $path;
            $data['size'] = $data['file']->getSize();
            $data['mime_type'] = $data['file']->getMimeType();
            unset($data['file']);
        }

        $media->update($data);
        return $media->fresh();
    }

    public function delete(int $id): bool
    {
        $media = $this->model->findOrFail($id);
        
        if ($media->path) {
            Storage::disk('public')->delete($media->path);
        }

        return $media->delete();
    }

    public function attachToModel(int $mediaId, string $modelType, int $modelId, array $data = []): bool
    {
        $media = $this->model->findOrFail($mediaId);
        
        return $media->update([
            'mediable_type' => $modelType,
            'mediable_id' => $modelId,
            'order' => $data['order'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
            'title' => $data['title'] ?? null
        ]);
    }

    public function detachFromModel(int $mediaId, string $modelType, int $modelId): bool
    {
        return $this->model
            ->where('id', $mediaId)
            ->where('mediable_type', $modelType)
            ->where('mediable_id', $modelId)
            ->update([
                'mediable_type' => null,
                'mediable_id' => null,
                'order' => null,
                'alt_text' => null,
                'title' => null
            ]);
    }
}
