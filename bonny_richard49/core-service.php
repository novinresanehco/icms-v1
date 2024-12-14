<?php

namespace App\Core\Service;

use App\Core\Contracts\ServiceInterface;
use App\Core\Security\SecurityManager;
use App\Core\Repository\BaseRepository;
use App\Core\Services\{ValidationService, AuditService, CacheManager};
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\{ServiceException, ValidationException};

abstract class BaseService implements ServiceInterface
{
    protected BaseRepository $repository;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected CacheManager $cache;
    
    public function __construct(
        BaseRepository $repository,
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        CacheManager $cache
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->cache = $cache;
    }

    protected function executeSecureOperation(callable $operation, array $context): mixed
    {
        return $this->security->executeSecureOperation(
            fn() => DB::transaction(fn() => $operation()),
            array_merge(['service' => static::class], $context)
        );
    }

    protected function validateServiceOperation(array $data, array $rules): array
    {
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Service operation validation failed');
        }
        return $data;
    }

    protected function logOperation(string $operation, array $context = [], array $data = []): void
    {
        $this->auditor->logServiceOperation(
            $operation,
            array_merge(
                ['service' => static::class],
                $context,
                ['data' => $data]
            )
        );
    }

    protected function cacheResult(string $key, $data, int $ttl = 3600): mixed
    {
        return $this->cache->remember($key, fn() => $data, $ttl);
    }

    protected function clearServiceCache(string $pattern = null): void
    {
        $prefix = $this->getServiceCachePrefix();
        
        if ($pattern) {
            $this->cache->deletePattern("{$prefix}:{$pattern}");
        } else {
            $this->cache->deletePattern("{$prefix}:*");
        }
    }

    protected function getServiceCachePrefix(): string
    {
        return strtolower(class_basename(static::class));
    }

    protected function getServiceCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->getServiceCachePrefix(),
            $operation,
            implode(':', $params)
        );
    }

    abstract protected function getDefaultValidationRules(): array;
}

class ContentService extends BaseService
{
    public function create(array $data): mixed
    {
        return $this->executeSecureOperation(
            function() use ($data) {
                $validated = $this->validateServiceOperation(
                    $data,
                    $this->getContentValidationRules()
                );
                
                $content = $this->repository->create($validated);
                
                $this->processContentMedia($content, $data['media'] ?? []);
                $this->updateContentMetadata($content, $data['metadata'] ?? []);
                
                $this->clearServiceCache();
                $this->logOperation('content_created', ['content_id' => $content->id]);
                
                return $content;
            },
            ['action' => 'create_content']
        );
    }

    public function update(int $id, array $data): mixed
    {
        return $this->executeSecureOperation(
            function() use ($id, $data) {
                $validated = $this->validateServiceOperation(
                    $data,
                    $this->getContentValidationRules()
                );
                
                $content = $this->repository->update($id, $validated);
                
                if (isset($data['media'])) {
                    $this->processContentMedia($content, $data['media']);
                }
                
                if (isset($data['metadata'])) {
                    $this->updateContentMetadata($content, $data['metadata']);
                }
                
                $this->clearServiceCache($id);
                $this->logOperation('content_updated', ['content_id' => $id]);
                
                return $content;
            },
            ['action' => 'update_content', 'content_id' => $id]
        );
    }

    public function publish(int $id): bool
    {
        return $this->executeSecureOperation(
            function() use ($id) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ServiceException("Content not found: {$id}");
                }
                
                $content->published_at = now();
                $content->save();
                
                $this->clearServiceCache($id);
                $this->logOperation('content_published', ['content_id' => $id]);
                
                return true;
            },
            ['action' => 'publish_content', 'content_id' => $id]
        );
    }

    protected function getDefaultValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived'
        ];
    }

    protected function getContentValidationRules(): array
    {
        return array_merge(
            $this->getDefaultValidationRules(),
            [
                'metadata' => 'array',
                'media' => 'array',
                'media.*.type' => 'required|string|in:image,video,document',
                'media.*.url' => 'required|url'
            ]
        );
    }

    protected function processContentMedia($content, array $media): void
    {
        // Implementation specific to media processing
    }

    protected function updateContentMetadata($content, array $metadata): void
    {
        // Implementation specific to metadata updates
    }
}