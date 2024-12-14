<?php

namespace App\Repositories;

use App\Models\Media;
use App\Core\Repositories\AbstractRepository;
use App\Core\Storage\StorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class MediaRepository extends AbstractRepository 
{
    protected array $searchable = ['filename', 'mime_type', 'alt_text', 'title'];
    protected StorageManager $storage;
    
    public function __construct(Media $model, StorageManager $storage)
    {
        parent::__construct($model);
        $this->storage = $storage;
    }

    public function upload(UploadedFile $file, array $metadata = []): Media
    {
        $this->beginTransaction();

        try {
            $path = $this->storage->store($file);
            
            $media = $this->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => array_merge($metadata, [
                    'dimensions' => $this->getImageDimensions($file),
                    'hash' => hash_file('sha256', $file->getPathname())
                ])
            ]);

            $this->commit();
            return $media;

        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function findByType(string $type): Collection
    {
        return $this->executeQuery(function() use ($type) {
            return $this->model->where('mime_type', 'LIKE', $type . '/%')->get();
        });
    }

    public function getUnused(int $days = 30): Collection
    {
        return $this->executeQuery(function() use ($days) {
            return $this->model->whereDoesntHave('contents')
                ->where('created_at', '<=', now()->subDays($days))
                ->get();
        });
    }

    public function deleteUnused(int $days = 30): int
    {
        $media = $this->getUnused($days);
        
        foreach ($media as $item) {
            $this->storage->delete($item->path);
            $item->delete();
        }

        return $media->count();
    }

    public function duplicate(int $id): Media
    {
        $original = $this->findOrFail($id);
        $newPath = $this->storage->copy($original->path);
        
        return $this->create([
            'filename' => $this->generateUniqueName($original->filename),
            'path' => $newPath,
            'mime_type' => $original->mime_type,
            'size' => $original->size,
            'metadata' => $original->metadata
        ]);
    }

    protected function getImageDimensions(UploadedFile $file): ?array
    {
        if (str_starts_with($file->getMimeType(), 'image/')) {
            [$width, $height] = getimagesize($file->getPathname());
            return compact('width', 'height');
        }
        return null;
    }

    protected function generateUniqueName(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        return sprintf(
            '%s_%s.%s',
            $name,
            uniqid(),
            $extension
        );
    }
}
