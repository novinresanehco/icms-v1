<?php

namespace App\Core\Repositories;

use App\Core\Security\{EncryptionService, AuditService};
use App\Models\Content;
use Illuminate\Support\Facades\{Cache, DB};
use App\Exceptions\RepositoryException;

class ContentRepository extends BaseRepository
{
    protected EncryptionService $encryption;
    protected AuditService $auditService;
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        EncryptionService $encryption,
        AuditService $auditService
    ) {
        $this->encryption = $encryption;
        $this->auditService = $auditService;
    }

    public function find(int $id): ?Content
    {
        return Cache::remember(
            "content:{$id}", 
            self::CACHE_TTL,
            fn() => Content::find($id)
        );
    }

    public function create(array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = Content::create($data);
            
            $this->handleMediaAttachments($content, $data['media'] ?? []);
            $this->handleCategorization($content, $data['categories'] ?? []);
            
            DB::commit();
            
            Cache::tags(['content'])->flush();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to create content', 0, $e);
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->find($id);
            if (!$content) {
                throw new RepositoryException('Content not found');
            }

            $content->update($data);
            
            if (isset($data['media'])) {
                $this->handleMediaAttachments($content, $data['media']);
            }
            
            if (isset($data['categories'])) {
                $this->handleCategorization($content, $data['categories']);
            }

            DB::commit();
            
            Cache::tags(['content'])->flush();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to update content', 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = $this->find($id);
            if (!$content) {
                throw new RepositoryException('Content not found');
            }

            $content->media()->detach();
            $content->categories()->detach();
            $content->delete();

            DB::commit();
            
            Cache::tags(['content'])->flush();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to delete content', 0, $e);
        }
    }

    public function list(array $filters = []): array
    {
        $cacheKey = 'content:list:' . md5(serialize($filters));

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($filters) {
            $query = Content::query();

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['category_id'])) {
                $query->whereHas('categories', function($q) use ($filters) {
                    $q->where('categories.id', $filters['category_id']);
                });
            }

            return $query->orderBy('created_at', 'desc')->get()->toArray();
        });
    }

    public function search(string $query, array $fields, array $filters = []): array
    {
        $cacheKey = 'content:search:' . md5($query . serialize($fields) . serialize($filters));

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($query, $fields, $filters) {
            $searchQuery = Content::query();

            foreach ($fields as $field) {
                $searchQuery->orWhere($field, 'LIKE', "%{$query}%");
            }

            if (isset($filters['status'])) {
                $searchQuery->where('status', $filters['status']);
            }

            return $searchQuery->orderBy('created_at', 'desc')->get()->toArray();
        });
    }

    public function updateStatus(int $id, string $status): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->find($id);
            if (!$content) {
                throw new RepositoryException('Content not found');
            }

            $content->update(['status' => $status]);
            $content->touch();

            DB::commit();
            
            Cache::tags(['content'])->flush();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to update content status', 0, $e);
        }
    }

    protected function handleMediaAttachments(Content $content, array $media): void
    {
        $content->media()->sync($media);
    }

    protected function handleCategorization(Content $content, array $categories): void
    {
        $content->categories()->sync($categories);
    }