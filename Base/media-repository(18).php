<?php

namespace App\Repositories;

use App\Models\Media;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaRepository extends BaseRepository
{
    public function __construct(Media $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function store(UploadedFile $file, array $metadata = []): Media
    {
        $path = Storage::disk('public')->put('media', $file);
        
        $media = $this->create([
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'path' => $path,
            'size' => $file->getSize(),
            'metadata' => $metadata
        ]);

        $this->clearCache();
        return $media;
    }

    public function findByType(string $type): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$type], function () use ($type) {
            return $this->model->where('mime_type', 'LIKE', $type . '%')
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function findUnused(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->doesntHave('contents')
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function updateMetadata(int $id, array $metadata): bool
    {
        $result = $this->update($id, ['metadata' => $metadata]);
        $this->clearCache($this->model->find($id));
        return $result;
    }

    public function delete(int $id): bool
    {
        $media = $this->find($id);
        if ($media) {
            Storage::disk('public')->delete($media->path);
        }
        return parent::delete($id);
    }
}
