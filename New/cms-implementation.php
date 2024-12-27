<?php

namespace App\Core\Content;

/**
 * Core content management implementation with integrated security and validation
 */
class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentRepository $repository;
    private CacheManager $cache;
    
    public function store(array $data): Content 
    {
        // Validate input with strict rules
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ]);

        // Generate security checksum
        $validated['checksum'] = $this->security->generateChecksum($validated);

        return DB::transaction(function() use ($validated) {
            // Store with protection
            $content = $this->repository->store($validated);

            // Clear relevant caches
            $this->cache->invalidate(['content', $content->id]);
            
            return $content;
        });
    }

    public function retrieve(int $id): Content
    {
        return $this->cache->remember(['content', $id], function() use ($id) {
            $content = $this->repository->find($id);

            // Verify data integrity
            if (!$this->security->verifyChecksum($content)) {
                throw new SecurityException('Content integrity verification failed');
            }

            return $content;
        });
    }
}

/**
 * Core media management with security controls
 */
class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MediaRepository $repository;
    private StorageManager $storage;

    public function store(UploadedFile $file, array $data): Media
    {
        // Validate file and metadata
        $this->validator->validateFile($file, [
            'mime_types' => ['image/*', 'application/pdf'],
            'max_size' => '10M'
        ]);

        $validated = $this->validator->validate($data, [
            'type' => 'required|in:image,document',
            'uploader_id' => 'required|exists:users,id'
        ]);

        return DB::transaction(function() use ($file, $validated) {
            // Store file securely
            $path = $this->storage->store($file, 'media');
            
            // Calculate file checksum
            $checksum = hash_file('sha256', $file->getRealPath());

            // Store media record
            $media = $this->repository->store([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'type' => $validated['type'],
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'metadata' => $this->extractMetadata($file),
                'uploader_id' => $validated['uploader_id'],
                'checksum' => $checksum
            ]);

            return $media;
        });
    }

    private function extractMetadata(UploadedFile $file): array
    {
        // Extract secure metadata based on file type
        $metadata = [];
        
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $metadata = [
                'dimensions' => getimagesize($file->getRealPath()),
                'exif' => $this->security->sanitizeExif($file)
            ];
        }

        return $metadata;
    }
}

/**
 * Secure content repository implementation
 */
class ContentRepository extends BaseRepository
{
    protected function model(): string 
    {
        return Content::class;
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return 'content:' . $operation . ':' . implode(':', $params);
    }

    public function findPublished(array $criteria = []): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('published', md5(serialize($criteria))),
            fn() => $this->model->published()
                ->with(['author', 'media'])
                ->where($criteria)
                ->latest('published_at')
                ->get()
        );
    }
}

/**
 * Media repository with integrated caching
 */
class MediaRepository extends BaseRepository
{
    protected function model(): string
    {
        return Media::class;
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return 'media:' . $operation . ':' . implode(':', $params);
    }

    public function findByContent(Content $content): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('content', $content->id),
            fn() => $content->media()
                ->orderBy('content_media.order')
                ->get()
        );
    }
}
