<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MediaRepository implements MediaRepositoryInterface
{
    protected Media $model;
    protected string $disk;
    
    public function __construct(Media $model, string $disk = 'public')
    {
        $this->model = $model;
        $this->disk = $disk;
    }

    public function store(UploadedFile $file, array $metadata = []): ?int
    {
        try {
            DB::beginTransaction();

            $fileName = $this->generateFileName($file);
            $path = $file->storeAs(
                $this->getStoragePath($metadata['collection'] ?? 'default'),
                $fileName,
                $this->disk
            );

            if (!$path) {
                throw new \Exception('Failed to store file');
            }

            $media = $this->model->create([
                'name' => $metadata['name'] ?? $file->getClientOriginalName(),
                'file_name' => $fileName,
                'disk' => $this->disk,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'collection' => $metadata['collection'] ?? 'default',
                'type' => $this->getFileType($file),
                'path' => $path,
                'url' => Storage::disk($this->disk)->url($path),
                'metadata' => array_merge($metadata, [
                    'original_name' => $file->getClientOriginalName(),
                    'extension' => $file->getClientOriginalExtension(),
                    'dimensions' => $this->getImageDimensions($file),
                ]),
            ]);

            if ($this->isImage($file)) {
                $this->generateImageVariants($media, $file);
            }

            DB::commit();
            return $media->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store media: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $mediaId, array $data): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->model->findOrFail($mediaId);
            $media->update([
                'name' => $data['name'] ?? $media->name,
                'collection' => $data['collection'] ?? $media->collection,
                'metadata' => array_merge($media->metadata ?? [], $data['metadata'] ?? []),
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update media: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $mediaId): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->model->findOrFail($mediaId);
            
            // Delete the original file
            Storage::disk($this->disk)->delete($media->path);
            
            // Delete conversions
            if (!empty($media->conversions)) {
                foreach ($media->conversions as $conversion) {
                    Storage::disk($this->disk)->delete($conversion['path']);
                }
            }
            
            // Delete responsive images
            if (!empty($media->responsive_images)) {
                foreach ($media->responsive_images as $image) {
                    Storage::disk($this->disk)->delete($image['path']);
                }
            }

            $media->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete media: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $mediaId): ?array
    {
        try {
            $media = $this->model->find($mediaId);
            return $media ? $media->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get media: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query();

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['collection'])) {
                $query->where('collection', $filters['collection']);
            }

            if (isset($filters['search'])) {
                $query->where('name', 'like', "%{$filters['search']}%");
            }

            return $query->latest()->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated media: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getByType(string $type): Collection
    {
        try {
            return $this->model->where('type', $type)->get();
        } catch (\Exception $e) {
            Log::error('Failed to get media by type: ' . $e->getMessage());
            return collect();
        }
    }

    public function getByCollection(string $collection): Collection
    {
        try {
            return $this->model->where('collection', $collection)->get();
        } catch (\Exception $e) {
            Log::error('Failed to get media by collection: ' . $e->getMessage());
            return collect();
        }
    }

    public function attachToModel(int $mediaId, string $modelType, int $modelId): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->model->findOrFail($mediaId);
            $media->mediable_type = $modelType;
            $media->mediable_id = $modelId;
            $media->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to attach media: ' . $e->getMessage());
            return false;
        }
    }

    public function detachFromModel(int $mediaId, string $modelType, int $modelId): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->model->findOrFail($mediaId);
            if ($media->mediable_type === $modelType && $media->mediable_id === $modelId) {
                $media->mediable_type = null;
                $media->mediable_id = null;
                $media->save();
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to detach media: ' . $e->getMessage());
            return false;
        }
    }

    public function optimize(int $mediaId, array $options = []): bool
    {
        try {
            $media = $this->model->findOrFail($mediaId);
            
            if (!$this->isImage($media)) {
                return false;
            }

            $image = Image::make($media->getFullPath());
            
            if (isset($options['quality'])) {
                $image->quality($options['quality']);
            }

            if (isset($options['width']) || isset($options['height'])) {
                $image->resize(
                    $options['width'] ?? null,
                    $options['height'] ?? null,
                    function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    }
                );
            }

            $image->save();

            $media->update([
                'size' => filesize($media->getFullPath()),
                'metadata' => array_merge($media->metadata, [
                    'optimized' => true,
                    'optimization_date' => now(),
                ]),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to optimize media: ' . $e->getMessage());
            return false;
        }
    }

    public function generateThumbnail(int $mediaId, array $dimensions): ?string
    {
        try {
            $media = $this->model->findOrFail($mediaId);
            
            if (!$this->isImage($media)) {
                return null;
            }

            $width = $dimensions['width'] ?? 150;
            $height = $dimensions['height'] ?? 150;
            
            $conversionName = "thumbnail_{$width}x{$height}";
            $thumbnailPath = $this->getStoragePath($media->collection) . '/thumbnails/' . 
                            pathinfo($media->file_name, PATHINFO_FILENAME) . 
                            "_{$width}x{$height}." . 
                            pathinfo($media->file_name, PATHINFO_EXTENSION);

            $image = Image::make($media->getFullPath());
            $image->fit($width, $height);
            
            Storage::disk($this->disk)->put($thumbnailPath, $image->encode());

            $conversions = $media->conversions ?? [];
            $conversions[$conversionName] = [
                'path' => $thumbnailPath,
                'url' => Storage::disk($this->disk)->url($thumbnailPath),
                'width' => $width,
                'height' => $height,
            ];

            $media->update(['conversions' => $conversions]);

            return Storage::disk($this->disk)->url($thumbnailPath);
        } catch (\Exception $e) {
            Log::error('Failed to generate thumbnail: ' . $e->getMessage());
            return null;
        }
    }

    public function updateMetadata(int $mediaId, array $metadata): bool
    {
        try {
            $media = $this->model->findOrFail($mediaId);
            $media->metadata = array_merge($media->metadata ?? [], $metadata);
            return $media->save();
        } catch (\Exception $e) {
            Log::error('Failed to update media metadata: ' . $e->getMessage());
            return false;
        }
    }

    protected function generateFileName(UploadedFile $file): string
    {
        return Str::random(40) . '.' . $file->getClientOriginalExtension();
    }

    protected function getStoragePath(string $collection): string
    {
        return 'media/' . $collection . '/' . date('Y/m/d');
    }

    protected function getFileType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        
        if (strpos($mime, 'image/') === 0) return 'image';
        if (strpos($mime, 'video/') === 0) return 'video';
        if (strpos($mime, 'audio/') === 0) return 'audio';
        if (strpos($mime, 'application/pdf') === 0) return 'pdf';
        
        return 'file';
    }

    protected function isImage($file): bool
    {
        if ($file instanceof UploadedFile) {
            return strpos($file->getMimeType(), 'image/') === 0;
        }
        
        if ($file instanceof Media) {
            return $file->type === 'image';
        }
        
        return false;
    }

    protected function getImageDimensions(UploadedFile $file): ?array
    {
        if ($this->isImage($file)) {
            try {
                [$width, $height] = getimagesize($file->path());
                return compact('width', 'height');
            } catch (\Exception $e) {
                Log::warning('Failed to get image dimensions: ' . $e->getMessage());
            }
        }
        return null;
    }

    protected function generateImageVariants(Media $media, UploadedFile $file): void
    {
        try {
            $config = config('media.variants', []);
            foreach ($config as $name => $dimensions) {
                $this->generateThumbnail($media->id, $dimensions);
            }

            if (config('media.responsive_images', false)) {
                $this->generateResponsiveImages($media, $file);
            }
        } catch (\Exception $e) {
            Log::error('Failed to generate image variants: ' . $e->getMessage());
        }
    }

    protected function generateResponsiveImages(Media $media, UploadedFile $file): void
    {