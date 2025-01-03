<?php

namespace App\Core\Media;

use App\Core\Repository\BaseRepository;
use Illuminate\Support\Facades\Cache;

class MediaRepository extends BaseRepository
{
    protected function getCacheKey(string $operation, ...$params): string 
    {
        return "media:{$operation}:" . implode(':', $params);
    }

    public function findById(int $id): ?Media
    {
        return Cache::remember(
            $this->getCacheKey('find', $id),
            3600,
            fn() => $this->model->findOrFail($id)
        );
    }

    public function create(array $data): Media
    {
        $media = $this->model->create($data);
        Cache::tags('media')->flush();
        return $media;
    }

    public function update(int $id, array $data): Media
    {
        $media = $this->findById($id);
        $media->update($data);
        Cache::tags('media')->flush();
        return $media;
    }

    public function delete(int $id): void
    {
        $this->model->findOrFail($id)->delete();
        Cache::tags('media')->flush();
    }
}
