<?php

namespace App\Core\Services;

use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use App\Events\MediaStored;
use Illuminate\Http\UploadedFile;
use App\Exceptions\MediaProcessingException;
use App\Core\Services\Contracts\FileOptimizerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Cache\CacheManager;
use App\Core\Events\EventDispatcher;

class MediaService
{
    public function __construct(
        protected MediaRepositoryInterface $mediaRepository,
        protected CacheManager $cache,
        protected EventDispatcher $events,
        protected FileOptimizerInterface $optimizer
    ) {}

    public function store(UploadedFile $file, array $options = []): Media 
    {
        $this->validateFile($file);

        DB::beginTransaction();
        try {
            $processedFile = $this->processFile($file, $options);
            
            $media = $this->mediaRepository->create([
                'filename' => $processedFile['filename'],
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $processedFile['size'],
                'path' => $processedFile['path'],
                'disk' => $options['disk'] ?? config('media.default_disk'),
                'meta' => $this->generateMediaMeta($file, $processedFile)
            ]);

            if ($this->isImage($file)) {
                $this->generateThumbnails($media);
            }

            DB::commit();
            
            $this->cache->tags(['media'])->flush();
            $this->events->dispatch(new MediaStored($media));

            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaProcessingException($e->getMessage(), 0, $e);
        }
    }

    protected function processFile(UploadedFile $file, array $options): array
    {
        $filename = $this->generateFilename($file);
        $path = $this->getStoragePath($options['directory'] ?? 'media');

        $storagePath = $file->storeAs($path, $filename, [
            'disk' => $options['disk'] ?? config('media.default_disk')
        ]);

        if ($this->isImage($file) && ($options['optimize'] ?? true)) {
            $this->optimizer->optimize(
                Storage::disk($options['disk'])->path($storagePath)
            );
        }

        return [
            'filename' => $filename,
            'path' => $storagePath,
            'size' => Storage::disk($options['disk'])->size($storagePath)
        ];
    }

    protected function generateThumbnails(Media $media): void
    {
        $sizes = config('media.thumbnail_sizes', [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [600, 600]
        ]);

        foreach ($sizes as $size => $dimensions) {
            $thumbnail = Image::make(
                Storage::disk($media->disk)->path($media->path)
            )->fit($dimensions[0], $dimensions[1]);

            $thumbnailPath = $this->getThumbnailPath($media, $size);
            Storage::disk($media->disk)->put(
                $thumbnailPath,
                $thumbnail->stream()->__toString()
            );

            $media->thumbnails()->create([
                'size' => $size,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'path' => $thumbnailPath
            ]);
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    'file',
                    'max:' . config('media.max_file_size', 10240),
                    'mimes:' . implode(',', config('media.allowed_mimes')),
                ]
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function generateMediaMeta(UploadedFile $file, array $processedFile): array
    {
        $meta = [
            'original_size' => $file->getSize(),
            'processed_size' => $processedFile['size'],
            'extension' => $file->getClientOriginalExtension(),
        ];

        if ($this->isImage($file)) {
            $image = Image::make($file);
            $meta['dimensions'] = [
                'width' => $image->width(),
                'height' => $image->height(),
            ];
            $meta['exif'] = $image->exif() ?? [];
        }

        return $meta;
    }

    protected function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    protected function generateFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            Str::random(32),
            time(),
            $file->getClientOriginalExtension()
        );
    }

    protected function getStoragePath(string $directory): string
    {
        return trim($directory, '/') . '/' . date('Y/m/d');
    }

    protected function getThumbnailPath(Media $media, string $size): string
    {
        $pathInfo = pathinfo($media->path);
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $size . '.' . $pathInfo['extension'];
    }
}
