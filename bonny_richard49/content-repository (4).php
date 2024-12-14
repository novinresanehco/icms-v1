<?php

namespace App\Core\CMS;

use App\Core\Interfaces\{
    RepositoryInterface,
    CacheManagerInterface
};
use App\Core\Models\{
    Content,
    ContentMetadata,
    ContentVersion
};
use App\Core\Exceptions\StorageException;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Content Repository with caching and validation
 */
class ContentRepository implements RepositoryInterface
{
    private CacheManagerInterface $cache;
    private LoggerInterface $logger;

    // Cache configuration
    private const CACHE_TTL = 3600;
    private const CACHE_PREFIX = 'content';

    public function __construct(
        CacheManagerInterface $cache,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Find content by ID with caching
     *
     * @throws StorageException
     */
    public function find(int $id): ?Content
    {
        try {
            return $this->cache->remember(
                $this->getCacheKey($id),
                self::CACHE_TTL,
                fn() => Content::with(['metadata', 'versions'])
                    ->find($id)
            );
        } catch (\Exception $e) {
            $this->handleStorageError('Error retrieving content', $e);
        }
    }

    /**
     * Create new content
     *
     * @throws StorageException
     */
    public function create(array $data): Content
    {
        try {
            return DB::transaction(function() use ($data) {
                // Create content
                $content = Content::create($data);

                // Clear cache for consistency
                $this->clearCache($content->id);

                return $content;
            });
        } catch (\Exception $e) {
            $this->handleStorageError('Error creating content', $e);
        }
    }

    /**
     * Update content by ID
     * 
     * @throws StorageException
     */
    public function update(int $id, array $data): Content
    {
        try {
            return DB::transaction(function() use ($id, $data) {
                // Update content
                $content = Content::findOrFail($id);
                $content->update($data);

                // Clear cache
                $this->clearCache($id);

                return $content;
            });
        } catch (\Exception $e) {
            $this->handleStorageError('Error updating content', $e);
        }
    }

    /**
     * Delete content by ID
     *
     * @throws StorageException
     */
    public function delete(int $id): void
    {
        try {
            DB::transaction(function() use ($id) {
                // Delete content
                Content::findOrFail($id)->delete();

                // Clear cache
                $this->clearCache($id);
            });
        } catch (\Exception $e) {
            $this->handleStorageError('Error deleting content', $e);
        }
    }

    /**
     * Create content version
     *
     * @throws StorageException
     */
    public function createVersion(ContentVersion $version): ContentVersion
    {
        try {
            return DB::transaction(function() use ($version) {
                $version->save();
                $this->clearCache($version->content_id);
                return $version;
            });
        } catch (\Exception $e) {
            $this->handleStorageError('Error creating content version', $e);
        }
    }

    /**
     * Create content metadata
     *
     * @throws StorageException 
     */
    public function createMetadata(ContentMetadata $metadata): ContentMetadata
    {
        try {
            return DB::transaction(function() use ($metadata) {
                $metadata->save();
                $this->clearCache($metadata->content_id);
                return $metadata;
            });
        } catch (\Exception $e) {
            $this->handleStorageError('Error creating content metadata', $e);
        }
    }

    /**
     * Get content versions
     *
     * @throws StorageException
     */
    public function getVersions(int $contentId): array
    {
        try {
            return ContentVersion::where('content_id', $contentId)
                ->orderBy('version', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            $this->handleStorageError('Error retrieving content versions', $e);
        }
    }

    /**
     * Get latest version number
     */
    public function getLatestVersionNumber(int $contentId): int
    {
        return ContentVersion::where('content_id', $contentId)
            ->max('version') ?? 0;
    }

    /**
     * Delete oldest version
     */
    public function deleteOldestVersion(int $contentId): void
    {
        ContentVersion::where('content_id', $contentId)
            ->orderBy('version')
            ->first()
            ?->delete();
    }

    /**
     * Clear content cache
     */
    protected function clearCache(int $id): void
    {
        $this->cache->forget($this->getCacheKey($id));
    }

    /**
     * Get cache key for content
     */
    protected function getCacheKey(int $id): string
    {
        return self::CACHE_PREFIX . ".{$id}";
    }

    /**
     * Handle storage errors
     *
     * @throws StorageException
     */
    protected function handleStorageError(string $message, \Exception $e): void
    {
        $this->logger