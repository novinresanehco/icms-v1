<?php

namespace App\Core\Media\Contracts;

interface MediaRepositoryInterface
{
    public function create(array $data): Media;
    public function update(int $id, array $data): Media;
    public function delete(int $id): bool;
    public function find(int $id): ?Media;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function attachToContent(int $contentId, array $mediaIds): void;
    public function detachFromContent(int $contentId, array $mediaIds): void;
    public function getContentMedia(int $contentId): Collection;
}

namespace App\Core\Media\Repositories;

use App\Core\Media\Models\Media;
use App\Core\Media\Contracts\MediaRepositoryInterface;
use App\Core\Media\Exceptions\MediaNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaRepository implements MediaRepositoryInterface
{
    protected Media $model;

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Media
    {
        $media = $this->model->create($data);
        $this->clearCache();
        return $media;
    }

    public function update(int $id, array $data): Media
    {
        $media = $this->find($id);
        
        if (!$media) {
            throw new MediaNotFoundException("Media with ID {$id} not found");
        }

        $media->update($data);
        $this->clearCache();
        return $media;
    }

    public function delete(int $id): bool
    {
        $media = $this->find($id);
        
        if (!$media) {
            throw new MediaNotFoundException("Media with ID {$id} not found");
        }

        $result = $media->delete();
        $this->clearCache();
        return $result;
    }

    public function find(int $id): ?Media
    {
        return Cache::tags(['media'])
            ->remember("media.{$id}", 3600, function () use ($id) {
                return $this->model->find($id);
            });
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('alt_text', 'like', "%{$filters['search']}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        $content = app(ContentRepositoryInterface::class)->find($contentId);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$contentId} not found");
        }

        $content->media()->attach($mediaIds);
        $this->clearContentMediaCache($contentId);
    }

    public function detachFromContent(int $contentId, array $mediaIds): void
    {
        $content = app(ContentRepositoryInterface::class)->find($contentId);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$contentId} not found");
        }

        $content->media()->detach($mediaIds);
        $this->clearContentMediaCache($contentId);
    }

    public function getContentMedia(int $contentId): Collection
    {
        return Cache::tags(['media', 'content'])
            ->remember("content.{$contentId}.media", 3600, function () use ($contentId) {
                return $this->model->whereHas('contents', function ($query) use ($contentId) {
                    $query->where('content_id', $contentId);
                })->get();
            });
    }

    protected function clearCache(): void
    {
        Cache::tags(['media'])->flush();
    }

    protected function clearContentMediaCache(int $contentId): void
    {
        Cache::tags(['media', 'content'])->forget("content.{$contentId}.media");
    }
}

namespace App\Core\Media\Services;

use App\Core\Media\Contracts\MediaRepositoryInterface;
use App\Core\Media\Events\MediaCreated;
use App\Core\Media\Events\MediaUpdated;
use App\Core\Media\Events\MediaDeleted;
use App\Core\Media\Exceptions\MediaValidationException;
use App\Core\Media\Processors\MediaProcessingPipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MediaService
{
    protected MediaRepositoryInterface $repository;
    protected MediaProcessingPipeline $pipeline;

    public function __construct(
        MediaRepositoryInterface $repository,
        MediaProcessingPipeline $pipeline
    ) {
        $this->repository = $repository;
        $this->pipeline = $pipeline;
    }

    public function uploadMedia(UploadedFile $file, array $data = []): Media
    {
        $this->validateFile($file);
        $this->validateMediaData($data);

        DB::beginTransaction();
        try {
            // Process the file through the pipeline
            $processedFile = $this->pipeline->process($file);

            // Create media record
            $mediaData = array_merge($data, [
                'name' => $data['name'] ?? $file->getClientOriginalName(),
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $processedFile->getPath(),
                'disk' => config('media.disk', 'public'),
                'metadata' => $processedFile->getMetadata(),
            ]);

            $media = $this->repository->create($mediaData);
            event(new MediaCreated($media));
            
            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            // Clean up any uploaded files if transaction failed
            if (isset($processedFile)) {
                Storage::disk(config('media.disk', 'public'))->delete($processedFile->getPath());
            }
            throw $e;
        }
    }

    public function update(int $id, array $data): Media
    {
        $this->validateMediaData($data, $id);

        DB::beginTransaction();
        try {
            $media = $this->repository->update($id, $data);
            event(new MediaUpdated($media));
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
            $media = $this->repository->find($id);
            
            // Delete the actual file
            Storage::disk($media->disk)->delete($media->path);
            
            $result = $this->repository->delete($id);
            event(new MediaDeleted($media));
            
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        $maxSize = config('media.max_size', 10240); // 10MB default
        $allowedTypes = config('media.allowed_types', ['image/*', 'application/pdf']);

        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    'file',
                    'max:' . $maxSize,
                    function ($attribute, $value, $fail) use ($allowedTypes) {
                        $mimeType = $value->getMimeType();
                        $allowed = false;
                        foreach ($allowedTypes as $type) {
                            if (str_contains($type, '*')) {
                                $pattern = str_replace('*', '.*', $type);
                                if (preg_match('#^' . $pattern . '$#', $mimeType)) {
                                    $allowed = true;
                                    break;
                                }
                            } elseif ($type === $mimeType) {
                                $allowed = true;
                                break;
                            }
                        }
                        if (!$allowed) {
                            $fail('The file type is not allowed.');
                        }
                    },
                ]
            ]
        );

        if ($validator->fails()) {
            throw new MediaValidationException($validator->errors()->first());
        }
    }

    protected function validateMediaData(array $data, ?int $id = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'alt_text' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new MediaValidationException($validator->errors()->first());
        }
    }
}

namespace App\Core\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'path',
        'size',
        'disk',
        'alt_text',
        'description',
        'metadata'
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class)
            ->withTimestamps()
            ->withPivot('order');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!isset($this->metadata['thumbnail_path'])) {
            return null;
        }
        return Storage::disk($this->disk)->url($this->metadata['thumbnail_path']);
    }
}
