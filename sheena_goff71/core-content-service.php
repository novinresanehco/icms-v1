<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Validators\ContentValidator;
use App\Core\Interfaces\ContentServiceInterface;
use App\Core\Events\ContentEvent;

class ContentService implements ContentServiceInterface
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private ContentValidator $validator;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        ContentValidator $validator,
        AuditLogger $auditLogger,
        MetricsCollector $metrics
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
    }

    public function create(array $data, User $user): ContentResult
    {
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $this->security->validateAccess($user, 'content.create');
            $this->validator->validateCreate($data);

            $content = $this->repository->create([
                ...$data,
                'created_by' => $user->id,
                'status' => ContentStatus::DRAFT,
                'version' => 1
            ]);

            $this->createVersion($content);
            $this->invalidateCache($content);
            
            event(new ContentEvent('content.created', $content));
            
            DB::commit();

            $this->auditLogger->log('content_created', [
                'content_id' => $content->id,
                'user_id' => $user->id
            ]);

            $this->metrics->record('content.create', microtime(true) - $startTime);

            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'create', $data);
            throw $e;
        }
    }

    public function update(int $id, array $data, User $user): ContentResult
    {
        try {
            DB::beginTransaction();

            $content = $this->repository->findOrFail($id);
            
            $this->security->validateAccess($user, 'content.update', $content);
            $this->validator->validateUpdate($data, $content);
            $this->createVersion($content);

            $content = $this->repository->update($content->id, [
                ...$data,
                'updated_by' => $user->id,
                'version' => $content->version + 1
            ]);

            $this->invalidateCache($content);
            
            event(new ContentEvent('content.updated', $content));
            
            DB::commit();

            $this->auditLogger->log('content_updated', [
                'content_id' => $content->id,
                'user_id' => $user->id,
                'version' => $content->version
            ]);

            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'update', ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    public function publish(int $id, User $user): ContentResult
    {
        try {
            DB::beginTransaction();

            $content = $this->repository->findOrFail($id);
            
            $this->security->validateAccess($user, 'content.publish', $content);
            $this->validator->validatePublish($content);

            $content = $this->repository->update($content->id, [
                'status' => ContentStatus::PUBLISHED,
                'published_at' => now(),
                'published_by' => $user->id
            ]);

            $this->invalidateCache($content);
            
            event(new ContentEvent('content.published', $content));
            
            DB::commit();

            $this->auditLogger->log('content_published', [
                'content_id' => $content->id,
                'user_id' => $user->id
            ]);

            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'publish', ['id' => $id]);
            throw $e;
        }
    }

    public function delete(int $id, User $user): bool
    {
        try {
            DB::beginTransaction();

            $content = $this->repository->findOrFail($id);
            
            $this->security->validateAccess($user, 'content.delete', $content);

            $this->repository->softDelete($content->id);
            $this->invalidateCache($content);
            
            event(new ContentEvent('content.deleted', $content));
            
            DB::commit();

            $this->auditLogger->log('content_deleted', [
                'content_id' => $content->id,
                'user_id' => $user->id
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'delete', ['id' => $id]);
            throw $e;
        }
    }

    public function get(int $id, ?User $user = null): ContentResult
    {
        $cacheKey = "content:{$id}";

        try {
            return Cache::remember($cacheKey, config('cache.ttl'), function() use ($id, $user) {
                $content = $this->repository->findOrFail($id);
                
                if ($user) {
                    $this->security->validateAccess($user, 'content.view', $content);
                } elseif (!$content->isPublished()) {
                    throw new ContentException('Content not found');
                }

                return new ContentResult($content);
            });

        } catch (\Exception $e) {
            $this->handleError($e, 'get', ['id' => $id]);
            throw $e;
        }
    }

    public function list(array $filters = [], ?User $user = null): ContentCollection
    {
        try {
            $query = $this->repository->newQuery();

            if (!$user) {
                $query->published();
            } else {
                $this->security->validateAccess($user, 'content.list');
                $this->applyUserFilters($query, $user);
            }

            $this->applyFilters($query, $filters);
            
            return new ContentCollection($query->paginate());

        } catch (\Exception $e) {
            $this->handleError($e, 'list', ['filters' => $filters]);
            throw $e;
        }
    }

    protected function createVersion(Content $content): void
    {
        $this->repository->createVersion([
            'content_id' => $content->id,
            'version' => $content->version,
            'data' => $content->toArray(),
            'created_by' => $content->updated_by ?? $content->created_by
        ]);
    }

    protected function invalidateCache(Content $content): void
    {
        Cache::forget("content:{$content->id}");
        Cache::tags(['content'])->flush();
    }

    protected function handleError(\Exception $e, string $operation, array $context): void
    {
        $this->auditLogger->logError('content_operation_failed', [
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('content.error', ['operation' => $operation]);
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->whereIn('status', (array)$filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->whereIn('type', (array)$filters['type']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }
    }

    protected function applyUserFilters(Builder $query, User $user): void
    {
        if (!$user->can('content.view.all')) {
            $query->where('created_by', $user->id);
        }
    }
}
