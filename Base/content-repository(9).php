<?php

namespace App\Repositories;

use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ContentRepository implements ContentRepositoryInterface
{
    protected string $table = 'contents';
    protected string $versionsTable = 'content_versions';

    /**
     * Create new content
     *
     * @param array $data
     * @return int|null Content ID if created, null on failure
     * @throws \InvalidArgumentException If required fields are missing
     */
    public function createContent(array $data): ?int
    {
        $this->validateContentData($data);

        try {
            DB::beginTransaction();

            $contentId = DB::table($this->table)->insertGetId(array_merge(
                $data,
                [
                    'slug' => $data['slug'] ?? Str::slug($data['title']),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ));

            // Create initial version
            $this->createVersion($contentId, $data);

            DB::commit();
            return $contentId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create content: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update existing content
     *
     * @param int $contentId
     * @param array $data
     * @return bool
     */
    public function updateContent(int $contentId, array $data): bool
    {
        try {
            DB::beginTransaction();

            $updated = DB::table($this->table)
                ->where('id', $contentId)
                ->update(array_merge(
                    $data,
                    [
                        'updated_at' => now(),
                        'slug' => $data['slug'] ?? Str::slug($data['title'])
                    ]
                )) > 0;

            if ($updated) {
                $this->createVersion($contentId, $data);
            }

            DB::commit();
            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update content: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get content by ID
     *
     * @param int $contentId
     * @param bool $withVersion Include latest version data
     * @return array|null
     */
    public function getContent(int $contentId, bool $withVersion = true): ?array
    {
        try {
            $content = DB::table($this->table)
                ->where('id', $contentId)
                ->first();

            if (!$content) {
                return null;
            }

            $contentArray = (array) $content;

            if ($withVersion) {
                $version = $this->getLatestVersion($contentId);
                if ($version) {
                    $contentArray['version_data'] = $version;
                }
            }

            return $contentArray;
        } catch (\Exception $e) {
            \Log::error('Failed to get content: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get content by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function getContentBySlug(string $slug): ?array
    {
        try {
            $content = DB::table($this->table)
                ->where('slug', $slug)
                ->first();

            return $content ? (array) $content : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get content by slug: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get paginated content list
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginatedContent(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = DB::table($this->table);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        return $query->orderBy('created_at', 'desc')
                    ->paginate($perPage);
    }

    /**
     * Delete content
     *
     * @param int $contentId
     * @return bool
     */
    public function deleteContent(int $contentId): bool
    {
        try {
            DB::beginTransaction();

            // Delete versions first
            DB::table($this->versionsTable)
                ->where('content_id', $contentId)
                ->delete();

            // Delete content
            $deleted = DB::table($this->table)
                ->where('id', $contentId)
                ->delete() > 0;

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete content: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get content versions
     *
     * @param int $contentId
     * @return Collection
     */
    public function getContentVersions(int $contentId): Collection
    {
        return collect(DB::table($this->versionsTable)
            ->where('content_id', $contentId)
            ->orderBy('created_at', 'desc')
            ->get());
    }

    /**
     * Get specific content version
     *
     * @param int $contentId
     * @param int $versionId
     * @return array|null
     */
    public function getContentVersion(int $contentId, int $versionId): ?array
    {
        try {
            $version = DB::table($this->versionsTable)
                ->where('content_id', $contentId)
                ->where('id', $versionId)
                ->first();

            return $version ? (array) $version : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get content version: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create new content version
     *
     * @param int $contentId
     * @param array $data
     * @return bool
     */
    protected function createVersion(int $contentId, array $data): bool
    {
        try {
            return DB::table($this->versionsTable)->insert([
                'content_id' => $contentId,
                'data' => json_encode($data),
                'created_by' => $data['author_id'] ?? auth()->id(),
                'created_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to create content version: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get latest content version
     *
     * @param int $contentId
     * @return array|null
     */
    protected function getLatestVersion(int $contentId): ?array
    {
        try {
            $version = DB::table($this->versionsTable)
                ->where('content_id', $contentId)
                ->orderBy('created_at', 'desc')
                ->first();

            return $version ? (array) $version : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get latest version: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate content data
     *
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateContentData(array $data): void
    {
        $required = ['title', 'content', 'type', 'status'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }
}
