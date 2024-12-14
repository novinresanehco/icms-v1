<?php

namespace App\Core\Repositories;

use App\Core\Models\Media;
use App\Core\Contracts\Repositories\MediaRepositoryInterface;
use App\Core\Exceptions\MediaNotFoundException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaRepository implements MediaRepositoryInterface
{
    protected Media $model;
    protected Storage $storage;

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $cacheKey = 'media.paginated.' . md5(serialize($filters) . $perPage);
        
        return Cache::tags(['media'])->remember(
            $cacheKey,
            config('cache.media.ttl'),
            fn() => $this->model
                ->when(isset($filters['type']), fn($q) => $q->where('type', $filters['type']))
                ->when(isset($filters['folder']), fn($q) => $q->where('folder', $filters['folder']))
                ->latest()
                ->paginate($perPage)
        );
    }

    public function findById(int $id): Media
    {
        $cacheKey = "media.{$id}";
        
        $media = Cache::tags(['media'])->remember(
            $cacheKey,
            config('cache.media.ttl'),
            fn() => $this->model->find($id)
        );

        if (!$media) {
            throw new MediaNotFoundException("Media with ID {$id} not found");
        }

        return $media;
    }

    public function store(UploadedFile $file, array $data = []): Media
    {
        DB::beginTransaction();
        try {
            $path = $file->store('media/' . date('Y/m'), 'public');
            
            $media = $this->model->create([
                'name' => $data['name'] ?? $file->getClientOriginalName(),
                'file_name' => basename($path),
                'mime_type' => $file->getMimeType(),
                'path' => $path,
                'size' => $file->getSize(),
                'folder' => $data['folder'] ?? 'default',
                'type' => $this->determineType($file->getMimeType()),
                'meta' => $data['meta'] ?? [],
            ]);
            
            $this->clearMediaCache();
            
            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            Storage::disk('public')->delete($path ?? '');
            throw $e;
        }
    }

    public function update(int $id, array $data): Media
    {
        DB::beginTransaction();
        try {
            $media = $this->findById($id);
            $media->update($data);
            
            $this->clearMediaCache();
            
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
            $media = $this->findById($id);
            
            Storage::disk('public')->delete($media->path);
            
            $deleted = $media->delete();
            
            $this->clearMediaCache();
            
            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByFolder(string $folder): Collection
    {
        $cacheKey = "media.folder.{$folder}";
        
        return Cache::tags(['media'])->remember(
            $cacheKey,
            config('cache.media.ttl'),
            fn() => $this->model
                ->where('folder', $folder)
                ->latest()
                ->get()
        );
    }

    public function getByType(string $type): Collection
    {
        $cacheKey = "media.type.{$type}";
        
        return Cache::tags(['media'])->remember(
            $cacheKey,
            config('cache.media.ttl'),
            fn() => $this->model
                ->where('type', $type)
                ->latest()
                ->get()
        );
    }

    protected function determineType(string $mimeType): string
    {
        if (strpos($mimeType, 'image/') === 0) {
            return 'image';
        }
        if (strpos($mimeType, 'video/') === 0) {
            return 'video';
        }
        if (strpos($mimeType, 'audio/') === 0) {
            return 'audio';
        }
        if (in_array($mimeType, ['application/pdf'])) {
            return 'document';
        }
        return 'other';
    }

    protected function clearMediaCache(): void
    {
        Cache::tags(['media'])->flush();
    }
}
