<?php

namespace App\Core\Repositories;

use App\Core\Interfaces\{
    ContentRepositoryInterface,
    CacheManagerInterface
};
use App\Core\Models\{Content, ContentVersion};
use App\Core\Exceptions\ContentNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentRepository implements ContentRepositoryInterface
{
    private CacheManagerInterface $cache;
    private int $cacheTtl;

    public function __construct(CacheManagerInterface $cache, int $cacheTtl = 3600)
    {
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    public function create(array $data): Content
    {
        try {
            DB::beginTransaction();

            $content = Content::create($data);

            // Create initial version
            ContentVersion::create([
                'content_id' => $content->id,
                'version' => 1,
                'data' => $data['data'],
                'metadata' => $data['metadata'],
                'created_by' => $data['created_by']
            ]);

            DB::commit();

            // Clear list caches
            $this->cache->invalidatePattern('content:list:*');

            return $content;

        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                'Failed to create content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function update(int $id, array $data): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->findOrFail($id);
            $content->update($data);

            // Create new version if data changed
            if (isset($data['data'])) {
                ContentVersion::create([
                    'content_id' => $id,
                    'version' => $data['version'],
                    'data' => $data['data'],
                    'metadata' => $data['metadata'] ?? $content->metadata,
                    'created_by' => $data['updated_by']
                ]);
            }

            DB::commit();

            // Clear caches
            $this->cache->invalidate("content:$id");
            $this->cache->invalidatePattern('content:list:*');

            return $content;

        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                'Failed to update content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function findOrFail(int $id): Content
    {
        $cacheKey = "content:$id";

        return $this->cache->remember(
            $cacheKey,
            $this->cacheTtl,
            function() use ($id) {
                $content = Content::where('id', $id)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$content) {
                    throw new ContentNotFoundException("Content not found: $id");
                }

                return $content;
            }
        );
    }

    public function list(array $filters): Collection
    {
        $cacheKey = 'content:list:' . md5(json_encode($filters));

        return $this->cache->remember(
            $cacheKey,
            $this->cacheTtl,
            function() use ($filters) {
                $query = Content::query()->whereNull('deleted_at');

                // Apply filters
                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                
                if (isset($filters['type'])) {
                    $query->where('type', $filters['type']);
                }

                if (isset($filters['search'])) {
                    $query->where(function($q) use ($filters) {
                        $q->where('metadata->title', 'like', "%{$filters['search']}%")
                          ->orWhere('metadata->summary', 'like', "%{$filters['search']}%");
                    });
                }

                // Add date range filter
                if (isset($filters['from_date'])) {
                    $query->where('created_at', '>=', $filters['from_date']);
                }

                if (isset($filters['to_date'])) {
                    $query->where('created_at', '<=', $filters['to_date']);
                }

                // Add sorting
                $sortField = $filters['sort_by'] ?? 'created_at';
                $sortDir = $filters['sort_dir'] ?? 'desc';
                $query->orderBy($sortField, $sortDir);

                // Add pagination
                $page = $filters['page'] ?? 1;
                $perPage = $filters['per_page'] ?? 20;

                return $query->paginate($perPage, ['*'], 'page', $page);
            }
        );
    }

    public function getVersions(int $contentId): Collection
    {
        $cacheKey = "content:$contentId:versions";

        return $this->cache->remember(
            $cacheKey,
            $this->cacheTtl,
            function() use ($contentId) {
                return ContentVersion::where('content_id', $contentId)
                    ->orderBy('version', 'desc')
                    ->get();
            }
        );
    }

    public function getVersion(int $contentId, int $version): ?ContentVersion
    {
        $cacheKey = "content:$contentId:version:$version";

        return $this->cache->remember(
            $cacheKey,
            $this->cacheTtl,
            function() use ($contentId, $version) {
                return ContentVersion::where('content_id', $contentId)
                    ->where('version', $version)
                    ->first();
            }
        );
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        try {
            DB::beginTransaction();

            $content = $this->findOrFail($id);
            $content->update([
                'deleted_at' => now(),
                'deleted_by' => $deletedBy
            ]);

            DB::commit();

            // Clear caches
            $this->cache->invalidate("content:$id");
            $this->cache->invalidatePattern('content:list:*');
            $this->cache->invalidatePattern("content:$id:version:*");

            return true;

        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                'Failed to delete content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function restore(int $id): bool
    {
        try {
            DB::beginTransaction();

            $content = Content::withTrashed()->findOrFail($id);
            $content->update([
                'deleted_at' => null,
                'deleted_by' => null
            ]);

            DB::commit();

            // Clear caches
            $this->cache->invalidate("content:$id");
            $this->cache->invalidatePattern('content:list:*');

            return true;

        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                'Failed to restore content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateVersion(int $contentId, int $version): void
    {
        $latestVersion = ContentVersion::where('content_id', $contentId)
            ->max('version');

        if ($version > $latestVersion) {
            throw new ContentRepositoryException(
                "Invalid version number: $version. Latest version is $latestVersion"
            );
        }
    }
}
