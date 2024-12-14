<?php

namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository, 
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function create(ContentData $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository),
            $context
        );
    }

    public function update(int $id, ContentData $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository),
            $context
        );
    }

    public function publish(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->repository),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository),
            $context
        );
    }
}

class ContentRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;

    public function create(array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->model->create($data);
            $this->cache->invalidate($this->getCacheKey($content->id));
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->model->findOrFail($id);
            $content->update($data);
            $this->cache->invalidate($this->getCacheKey($id));
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}

abstract class ContentOperation implements CriticalOperation
{
    protected ContentRepository $repository;
    protected ContentData $data;

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.manage'];
    }

    abstract public function execute(): OperationResult;
}

class CreateContentOperation extends ContentOperation
{
    public function __construct(ContentData $data, ContentRepository $repository)
    {
        $this->data = $data;
        $this->repository = $repository;
    }

    public function execute(): OperationResult
    {
        $content = $this->repository->create($this->data->toArray());
        return new OperationResult($content);
    }
}

class UpdateContentOperation extends ContentOperation
{
    private int $id;

    public function __construct(int $id, ContentData $data, ContentRepository $repository)
    {
        $this->id = $id;
        $this->data = $data;
        $this->repository = $repository;
    }

    public function execute(): OperationResult
    {
        $content = $this->repository->update($this->id, $this->data->toArray());
        return new OperationResult($content);
    }
}

class PublishContentOperation extends ContentOperation
{
    private int $id;

    public function __construct(int $id, ContentRepository $repository)
    {
        $this->id = $id;
        $this->repository = $repository;
    }

    public function execute(): OperationResult
    {
        $content = $this->repository->update($this->id, ['status' => 'published']);
        return new OperationResult($content);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.publish'];
    }
}