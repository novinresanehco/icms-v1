<?php

namespace App\CMS\Core;

class ContentManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentRepository $repository;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function createContent(array $data): Content
    {
        return $this->executeSecure(function() use ($data) {
            // Validate input
            $validated = $this->validator->validate($data, [
                'title' => 'required|string|max:200',
                'body' => 'required|string',
                'type' => 'required|in:page,post,article',
                'status' => 'required|in:draft,published',
                'meta' => 'array'
            ]);

            // Create content with security
            $content = $this->repository->create($validated);
            
            // Clear relevant caches
            $this->cache->tags(['content'])->flush();
            
            // Log creation
            $this->logger->logContentCreation($content);
            
            return $content;
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->executeSecure(function() use ($id, $data) {
            $content = $this->repository->findOrFail($id);
            
            $validated = $this->validator->validate($data, [
                'title' => 'string|max:200',
                'body' => 'string',
                'status' => 'in:draft,published',
                'meta' => 'array'
            ]);

            $content->update($validated);
            
            $this->cache->tags(['content', "content.$id"])->flush();
            $this->logger->logContentUpdate($content);
            
            return $content;
        });
    }

    public function publishContent(int $id): void
    {
        $this->executeSecure(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            
            if (!$content->isPublishable()) {
                throw new ContentException('Content cannot be published');
            }

            $content->publish();
            $this->repository->save($content);
            
            $this->cache->tags(['content', "content.$id"])->flush();
            $this->logger->logContentPublication($content);
        });
    }

    private function executeSecure(callable $operation): mixed
    {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();
            
            $result = $operation();
            
            DB::commit();
            
            $this->metrics->recordTiming(
                'cms.operation.duration',
                microtime(true) - $startTime
            );
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->logger->logError('Content operation failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->metrics->incrementCounter('cms.operation.errors');
            
            throw $e;
        }
    }
}

class MediaManager 
{
    private SecurityManager $security;
    private StorageManager $storage;
    private ValidationService $validator;
    private MediaRepository $repository;
    private ImageProcessor $imageProcessor;

    public function storeMedia(UploadedFile $file): Media
    {
        return $this->executeSecure(function() use ($file) {
            // Validate file
            $this->validator->validateFile($file, [
                'mime_types' => ['image/jpeg', 'image/png', 'image/gif'],
                'max_size' => '10240', // 10MB
                'malware_scan' => true
            ]);

            // Process and store file
            $path = $this->storage->storeSecurely($file);
            $optimized = $this->imageProcessor->process($path);
            
            // Create database record
            $media = $this->repository->create([
                'path' => $optimized,
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'meta' => $this->imageProcessor->getMetadata()
            ]);
            
            return $media;
        });
    }
}

class VersionManager
{
    private SecurityManager $security;
    private VersionRepository $repository;
    private ContentRepository $contentRepo;
    private DiffGenerator $differ;

    public function createVersion(Content $content): Version
    {
        return $this->executeSecure(function() use ($content) {
            $previous = $this->repository->getLatestVersion($content);
            
            $diff = $previous ? 
                $this->differ->generateDiff($previous, $content) :
                $content->toArray();
                
            return $this->repository->create([
                'content_id' => $content->id,
                'data' => $diff,
                'created_by' => auth()->id()
            ]);
        });
    }

    public function restoreVersion(int $versionId): Content
    {
        return $this->executeSecure(function() use ($versionId) {
            $version = $this->repository->findOrFail($versionId);
            $content = $this->contentRepo->findOrFail($version->content_id);
            
            $restored = $this->differ->applyDiff($content, $version->data);
            $this->contentRepo->save($restored);
            
            return $restored;
        });
    }
}

class CategoryManager
{
    private SecurityManager $security;
    private CategoryRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;

    public function createCategory(array $data): Category
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validator->validate($data, [
                'name' => 'required|string|max:100|unique:categories',
                'slug' => 'required|string|max:100|unique:categories',
                'parent_id' => 'nullable|exists:categories,id'
            ]);

            $category = $this->repository->create($validated);
            $this->cache->tags(['categories'])->flush();
            
            return $category;
        });
    }
}
