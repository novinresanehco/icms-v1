<?php

namespace App\Core\Repositories;

use App\Core\Models\Media;
use App\Core\Exceptions\MediaNotFoundException;
use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MediaRepository implements MediaRepositoryInterface 
{
    private Media $model;
    
    public function __construct(Media $media) 
    {
        $this->model = $media;
    }
    
    public function findById(int $id): ?Media 
    {
        try {
            return $this->model->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            throw new MediaNotFoundException("Media with ID {$id} not found");
        }
    }
    
    public function store(array $data): Media 
    {
        return $this->model->create($data);
    }
    
    public function update(int $id, array $data): ?Media 
    {
        $media = $this->findById($id);
        $media->update($data);
        return $media->fresh();
    }
    
    public function delete(int $id): bool 
    {
        return (bool) $this->findById($id)->delete();
    }
    
    public function findByType(string $type): Collection 
    {
        return $this->model->where('type', $type)->get();
    }
}
