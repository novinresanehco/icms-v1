<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Storage, File};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\MediaException;

class MediaManager
{
    private SecurityManager $security;
    private MediaRepository $repository;
    private MediaValidator $validator;
    private ImageProcessor $processor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        MediaValidator $validator,
        ImageProcessor $processor,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->config = $config;
    }

    public function upload(UploadedFile $file, array $metadata, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            function() use ($file, $metadata) {
                // Validate file and metadata
                $this->validator->validateFile($file);
                $validated = $this->validator->validateMetadata($metadata);

                // Process and store file
                $processedFile = $this->processor->process($file);
                $path = Storage::disk('secure')->put(
                    $this->getStoragePath(),
                    $processedFile
                );

                // Generate thumbnails if image
                $thumbnails = [];
                if ($this->processor->isImage($file)) {
                    $thumbnails = $this->generateThumbnails($file);
                }

                // Create database record
                $mediaData = array_merge($validated, [
                    'path' => $path,
                    'type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'thumbnails' => $thumbnails
                ]);

                return $this->repository->create($mediaData);
            },
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $media = $this->repository->find($id);
                if (!$media) {
                    throw new MediaException("Media not found: {$id}");
                }

                // Delete physical files
                Storage::disk('secure')->delete($media->path);
                foreach ($media->thumbnails as $thumbnail) {
                    Storage::disk('secure')->delete($thumbnail);
                }

                // Delete database record
                return $this->repository->delete($id);
            },
            $context
        );
    }

    public function get(int $id, SecurityContext $context): ?Media
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->repository->find($id),
            $context
        );
    }

    private function generateThumbnails(UploadedFile $file): array
    {
        $thumbnails = [];
        foreach ($this->config['thumbnail_sizes'] as $size => $dimensions) {
            $thumbnail = $this->processor->createThumbnail(
                $file,
                $dimensions['width'],
                $dimensions['height']
            );
            
            $path = Storage::disk('secure')->put(
                $this->getThumbnailPath($size),
                $thumbnail
            );
            
            $thumbnails[$size] = $path;
        }
        return $thumbnails;
    }

    private function getStoragePath(): string
    {
        return date('Y/m/d') . '/' . uniqid('media_', true);
    }

    private function getThumbnailPath(string $size): string
    {
        return 'thumbnails/' . date('Y/m/d') . '/' . uniqid("thumb_{$size}_", true);
    }
}

class ImageProcessor
{
    private array $config;

    public function process(UploadedFile $file): UploadedFile
    {
        if (!$this->isImage($file)) {
            return $file;
        }

        $image = Image::make($file);

        // Apply optimizations
        $image->orientate();
        
        if ($this->config['max_dimensions']) {
            $image->resize(
                $this->config['max_dimensions']['width'],
                $this->config['max_dimensions']['height'],
                function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                }
            );
        }

        // Optimize quality
        $image->save(null, $this->config['jpeg_quality']);

        return $image;
    }

    public function createThumbnail(
        UploadedFile $file,
        int $width,
        int $height
    ): UploadedFile {
        $image = Image::make($file);

        $image->fit($width, $height, function ($constraint) {
            $constraint->upsize();
        });

        $image->save(null, $this->config['thumbnail_quality']);

        return $image;
    }

    public function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }
}

class MediaRepository
{
    public function create(array $data): Media
    {
        return DB::transaction(function() use ($data) {
            $id = DB::table('media')->insertGetId($data);
            return $this->find($id);
        });
    }

    public function find(int $id): ?Media
    {
        $data = DB::table('media')->find($id);
        return $data ? new Media($data) : null;
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            return DB::table('media')->delete($id) > 0;
        });
    }
}

class MediaValidator
{
    private array $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain'
    ];

    private array $metadataRules = [
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'alt_text' => 'nullable|string|max:255',
        'tags' => 'nullable|array'
    ];

    public function validateFile(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedTypes)) {
            throw new MediaException('Invalid file type');
        }

        if ($file->getSize() > config('media.max_size')) {
            throw new MediaException('File size exceeds limit');
        }
    }

    public function validateMetadata(array $metadata): array
    {
        $validator = validator($metadata, $this->metadataRules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }
}

class Media
{
    public int $id;
    public string $title;
    public ?string $description;
    public string $path;
    public string $type;
    public int $size;
    public array $thumbnails;
    public ?string $alt_text;
    public array $tags;
    public string $created_at;
    public string $updated_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
