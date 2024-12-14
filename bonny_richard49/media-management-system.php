<?php

namespace App\Core\Media;

use App\Core\Security\{SecurityManager, ValidationService};
use App\Core\Exceptions\{MediaException, ValidationException};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB, Cache};
use Intervention\Image\Facades\Image;

class MediaManager
{
    private SecurityManager $security;
    private MediaRepository $repository;
    private ValidationService $validator;
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private int $maxFileSize = 10485760; // 10MB

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function upload(UploadedFile $file, array $context): Media
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processUpload($file, $context),
            ['action' => 'media_upload', 'file' => $file->getClientOriginalName()]
        );
    }

    protected function processUpload(UploadedFile $file, array $context): Media
    {
        $this->validateFile($file);

        DB::beginTransaction();
        try {
            $media = $this->repository->create([
                'filename' => $this->generateSecureFilename($file),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $this->extractMetadata($file)
            ]);

            $path = $this->storeFile($file, $media);
            $media->path = $path;

            if ($this->isImage($file)) {
                $this->processImage($file, $media);
            }

            $this->repository->update($media->id, ['path' => $path]);
            
            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->cleanupFailedUpload($file, $media ?? null);
            throw new MediaException('Upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedTypes)) {
            throw new ValidationException('Invalid file type');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new ValidationException('File size exceeds limit');
        }

        if (!$this->validator->validateFileContent($file)) {
            throw new ValidationException('File content validation failed');
        }
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            hash('sha256', uniqid('', true)),
            time(),
            $file->getClientOriginalExtension()
        );
    }

    protected function storeFile(UploadedFile $file, Media $media): string
    {
        $path = sprintf(
            'media/%s/%s',
            date('Y/m'),
            $media->filename
        );

        Storage::put(
            $path,
            file_get_contents($file->getRealPath()),
            ['visibility' => 'private']
        );

        return $path;
    }

    protected function processImage(UploadedFile $file, Media $media): void
    {
        $image = Image::make($file->getRealPath());

        // Generate thumbnails
        $sizes = [
            'small' => [200, 200],
            'medium' => [800, 800],
            'large' => [1600, 1600]
        ];

        foreach ($sizes as $size => [$width, $height]) {
            $thumb = $image->fit($width, $height);
            
            $thumbPath = sprintf(
                'media/thumbnails/%s/%s/%s',
                $size,
                date('Y/m'),
                $media->filename
            );

            Storage::put(
                $thumbPath,
                $thumb->encode()->encoded,
                ['visibility' => 'private']
            );

            $media->thumbnails[$size] = $thumbPath;
        }

        // Extract and store image metadata
        $media->metadata = array_merge(
            $media->metadata ?? [],
            [
                'dimensions' => [
                    'width' => $image->width(),
                    'height' => $image->height()
                ],
                'exif' => $this->sanitizeExif($image->exif() ?? [])
            ]
        );
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath())
        ];
    }

    protected function sanitizeExif(array $exif): array
    {
        $allowedKeys = [
            'Make', 'Model', 'DateTimeOriginal', 
            'ExposureTime', 'FNumber', 'ISOSpeedRatings'
        ];

        return array_intersect_key($exif, array_flip($allowedKeys));
    }

    protected function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }

    protected function cleanupFailedUpload(UploadedFile $file, ?Media $media): void
    {
        if ($media && $media->path) {
            Storage::delete($media->path);
            
            if (!empty($media->thumbnails)) {
                foreach ($media->thumbnails as $path) {
                    Storage::delete($path);
                }
            }
        }
    }

    public function get(int $id, array $context): ?Media
    {
        return Cache::remember(
            "media.{$id}",
            3600,
            fn() => $this->repository->find($id)
        );
    }

    public function delete(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $media = $this->repository->find($id);
                
                if (!$media) {
                    return false;
                }

                Storage::delete($media->path);
                
                if (!empty($media->thumbnails)) {
                    foreach ($media->thumbnails as $path) {
                        Storage::delete($path);
                    }
                }

                Cache::forget("media.{$id}");
                return $this->repository->delete($id);
            },
            ['action' => 'media_delete', 'media_id' => $id]
        );
    }
}

class MediaRepository
{
    public function create(array $data): Media
    {
        return Media::create($data);
    }

    public function update(int $id, array $data): bool
    {
        return Media::where('id', $id)->update($data);
    }

    public function find(int $id): ?Media
    {
        return Media::find($id);
    }

    public function delete(int $id): bool
    {
        return Media::destroy($id) > 0;
    }
}

class Media extends Model
{
    protected $fillable = [
        'filename',
        'path',
        'mime_type',
        'size',
        'metadata',
        'thumbnails'
    ];

    protected $casts = [
        'metadata' => 'array',
        'thumbnails' => 'array'
    ];

    protected $hidden = [
        'path'
    ];
}
