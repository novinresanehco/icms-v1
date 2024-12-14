<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected array $searchableFields = ['filename', 'alt', 'title', 'mime_type'];
    protected array $filterableFields = ['type', 'collection', 'status'];

    public function findByHash(string $hash): ?Media
    {
        return $this->model
            ->where('hash', $hash)
            ->first();
    }

    public function getByCollection(string $collection, array $with = []): Collection
    {
        return $this->model
            ->with($with)
            ->where('collection', $collection)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function storeFile(array $data, $file): Media
    {
        $path = Storage::disk('public')->put('media', $file);
        $hash = hash_file('md5', $file);

        $data['path'] = $path;
        $data['hash'] = $hash;
        $data['size'] = $file->getSize();
        $data['mime_type'] = $file->getMimeType();

        return $this->create($data);
    }

    public function deleteWithFile(int $id): bool
    {
        $media = $this->findById($id);
        
        if ($media) {
            Storage::disk('public')->delete($media->path);
            return $media->delete();
        }

        return false;
    }

    public function duplicateCheck($file): ?Media
    {
        $hash = hash_file('md5', $file);
        return $this->findByHash($hash);
    }

    public function getUsageStats(): array
    {
        return [
            'total_size' => $this->model->sum('size'),
            'count_by_type' => $this->model
                ->groupBy('mime_type')
                ->selectRaw('mime_type, count(*) as count')
                ->pluck('count', 'mime_type')
                ->toArray(),
            'total_files' => $this->model->count(),
            'collections' => $this->model
                ->groupBy('collection')
                ->selectRaw('collection, count(*) as count')
                ->pluck('count', 'collection')
                ->toArray()
        ];
    }
}
