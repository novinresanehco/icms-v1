<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Repository\ContentRepository;
use App\Core\Services\CacheManager;

class ContentManager 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository, 
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
    }

    /**
     * Create new content with security validation and caching
     */
    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($this->repository, $data)
        );
    }

    /**
     * Update content with version control and security
     */
    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($this->repository, $id, $data)
        );
    }

    /**
     * Delete content with security verification
     */
    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($this->repository, $id)
        );
    }

    /**
     * Get content with caching and security check
     */
    public function get(int $id): ?Content
    {
        return $this->cache->remember(
            "content.$id",
            60,
            fn() => $this->security->executeCriticalOperation(
                new GetContentOperation($this->repository, $id)
            )
        );
    }

    /**
     * List content with pagination and security
     */
    public function list(array $criteria, int $page = 1): PaginatedResult
    {
        return $this->cache->remember(
            "content.list." . md5(serialize($criteria)) . ".$page",
            30,
            fn() => $this->security->executeCriticalOperation(
                new ListContentOperation($this->repository, $criteria, $page)
            )
        );
    }
}

abstract class ContentOperation implements CriticalOperation
{
    protected ContentRepository $repository;
    protected array $data;

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.manage'];
    }
}

class CreateContentOperation extends ContentOperation
{
    public function __construct(ContentRepository $repository, array $data)
    {
        $this->repository = $repository;
        $this->data = $data;
    }

    public function execute(): Content
    {
        return $this->repository->create($this->data);
    }
}

class UpdateContentOperation extends ContentOperation
{
    private int $id;

    public function __construct(ContentRepository $repository, int $id, array $data)
    {
        $this->repository = $repository;
        $this->id = $id;
        $this->data = $data;
    }

    public function execute(): Content
    {
        return $this->repository->update($this->id, $this->data);
    }
}

class ContentRepository
{
    protected $model;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model->create($data);
            
            // Create version record
            $this->createVersion($content);
            
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->model->findOrFail($id);
            
            // Create version before update
            $this->createVersion($content);
            
            $content->update($data);
            return $content;
        });
    }

    protected function createVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => json_encode($content->toArray()),
            'created_by' => auth()->id()
        ]);
    }
}
