<?php

namespace App\Core\Services;

use App\Core\Models\Media;
use App\Core\Services\Contracts\MediaServiceInterface;
use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaService implements MediaServiceInterface
{
    public function __construct(
        private MediaRepositoryInterface $repository
    ) {}

    public function getMedia(int $id): ?Media
    {
        return Cache::tags(['media'])->remember(
            "media.{$id}",
            now()->addHour(),
            fn() => $this->repository->findById($id)
        );
    }

    public function getModelMedia(string $modelType, int $modelId): Collection
    {
        return Cache::tags(['media'])->remember(
            "media.model.{$modelType}.{$modelId}",
            now()->addHour(),
            fn() => $this->repository->getForModel($modelType, $modelId)
        );
    }

    public function getAllMedia(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getAllPaginated($perPage);
    }

    public function getMediaByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByType($type, $perPage);
    }

    public function uploadMedia(array $data): Media
    {
        $media = $this->repository->store($data);
        Cache::tags(['media'])->flush();
        return $media;
    }

    public function updateMedia(int $id, array $data): Media
    {
        $media = $this->repository->update($id, $data);
        Cache::tags(['media'])->flush();
        return $media;
    }

    public function deleteMedia(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['media'])->flush();
        return $result;
    }

    public function attachMediaToModel(int $mediaId, string $modelType, int $modelId, array $data = []): bool
    {
        $result = $this->repository->attachToModel($mediaId, $modelType, $modelId, $data);
        Cache::tags(['media'])->flush();
        return $result;
    }

    public function detachMediaFromModel(int $mediaId, string $modelType, int $modelId): bool
    {
        $result = $this->repository->detachFromModel($mediaId, $modelType, $modelId);
        Cache::tags(['media'])->flush();
        return $result;
    }
}
