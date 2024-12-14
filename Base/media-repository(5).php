<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected array $searchableFields = ['name', 'alt', 'title', 'description'];
    protected array $filterableFields = ['type', 'collection', 'status'];

    public function __construct(Media $model)
    {
        parent::__construct($model);
    }

    public function store(array $data, $file): ?Media
    {
        try {
            DB::beginTransaction();

            $path = $file->store($data['collection'] ?? 'media', 'public');
            
            $media = $this->create([
                'name' => $data['name'] ?? $file->getClientOriginalName(),
                'file_name' => basename($path),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'collection' => $data['collection'] ?? 'default',
                'alt' => $data['alt'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'disk' => 'public',
                'path' => $path,
                'type' => Str::before($file->getMimeType(), '/'),
                'metadata' => $this->extractMetadata($file)
            ]);

            DB::commit();
            $this->clearModelCache();

            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store media: ' . $e->getMessage());
            return null;
        }
    }

    public function getByCollection(string $collection): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("collection.{$collection}"),
                $this->cacheTTL,
                fn() => $this->model->where('collection', $collection)
                    ->orderBy('created_at', 'desc')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get media by collection: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function deleteWithFile(int $id): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->find($id);
            if (!$media) {
                throw new \Exception('Media not found');
            }

            Storage::disk($media->disk)->delete($media->path);
            $media->delete();

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete media with file: ' . $e->getMessage());
            return false;
        }
    }

    protected function extractMetadata($file): array
    {
        $metadata = [];

        if (Str::startsWith($file->getMimeType(), 'image/')) {
            $imageSize = getimagesize($file->getPathname());
            $metadata['dimensions'] = [
                'width' => $imageSize[0] ?? null,
                'height' => $imageSize[1] ?? null
            ];
        }

        return $metadata;
    }
}
