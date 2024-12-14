<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaRepository implements MediaRepositoryInterface
{
    public function __construct(protected Media $model) {}

    public function create(array $data): Media
    {
        return $this->model->create($data);
    }

    public function findById(int $id): ?Media
    {
        return $this->model->find($id);
    }

    public function findByIds(array $ids): Collection
    {
        return $this->model->whereIn('id', $ids)->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->latest()->paginate($perPage);
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id);
    }

    public function findByType(string $mimeType, array $options = []): Collection
    {
        $query = $this->model->where('mime_type', 'LIKE', $mimeType . '%');
        
        if (isset($options['order'])) {
            $query->orderBy($options['order'], $options['direction'] ?? 'asc');
        }
        
        return $query->get();
    }

    public function updateMeta(int $id, array $meta): bool
    {
        $media = $this->findById($id);
        if (!$media) {
            return false;
        }

        $media->meta = array_merge($media->meta ?? [], $meta);
        return $media->save();
    }
}
