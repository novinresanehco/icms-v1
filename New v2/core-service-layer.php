<?php

namespace App\Core\Services;

class ContentService implements ContentServiceInterface
{
    private ContentRepository $repository;
    private SecurityValidator $security;
    private CacheManager $cache;
    private EventDispatcher $events;

    public function __construct(
        ContentRepository $repository,
        SecurityValidator $security,
        CacheManager $cache,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->events = $events;
    }

    public function store(array $data): Content
    {
        $operation = new StoreOperation($data, $this->repository);
        $result = $this->security->validateOperation($operation);
        $this->events->dispatch(new ContentCreated($result->getContent()));
        return $result->getContent();
    }

    public function update(int $id, array $data): Content
    {
        $operation = new UpdateOperation($id, $data, $this->repository);
        $result = $this->security->validateOperation($operation);
        $this->cache->invalidate(['content', $id]);
        $this->events->dispatch(new ContentUpdated($result->getContent()));
        return $result->getContent();
    }

    public function delete(int $id): bool
    {
        $operation = new DeleteOperation($id, $this->repository);
        $result = $this->security->validateOperation($operation);
        $this->cache->invalidate(['content', $id]);
        $this->events->dispatch(new ContentDeleted($id));
        return $result->isSuccess();
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(['content', $id], function() use ($id) {
            $operation = new FindOperation($id, $this->repository);
            $result = $this->security->validateOperation($operation);
            return $result->getContent();
        });
    }
}

abstract class BaseOperation implements Operation
{
    protected Repository $repository;
    protected array $data;

    public function __construct(array $data, Repository $repository)
    {
        $this->data = $data;
        $this->repository = $repository;
    }

    public function getData(): array
    {
        return $this->data;
    }

    abstract public function execute(): OperationResult;
}

class StoreOperation extends BaseOperation
{
    public function execute(): OperationResult
    {
        $content = $this->repository->store($this->data);
        return new OperationResult($content);
    }
}

class UpdateOperation extends BaseOperation
{
    private int $id;

    public function __construct(int $id, array $data, Repository $repository)
    {
        parent::__construct($data, $repository);
        $this->id = $id;
    }

    public function execute(): OperationResult
    {
        $content = $this->repository->update($this->id, $this->data);
        return new OperationResult($content);
    }
}

class DeleteOperation extends BaseOperation
{
    private int $id;

    public function __construct(int $id, Repository $repository)
    {
        parent::__construct([], $repository);
        $this->id = $id;
    }

    public function execute(): OperationResult
    {
        $success = $this->repository->delete($this->id);
        return new OperationResult(null, $success);
    }
}

class FindOperation extends BaseOperation
{
    private int $id;

    public function __construct(int $id, Repository $repository)
    {
        parent::__construct([], $repository);
        $this->id = $id;
    }

    public function execute(): OperationResult
    {
        $content = $this->repository->find($this->id);
        return new OperationResult($content);
    }
}

class OperationResult
{
    private ?Model $content;
    private bool $success;

    public function __construct(?Model $content, bool $success = true)
    {
        $this->content = $content;
        $this->success = $success;
    }

    public function getContent(): ?Model
    {
        return $this->content;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}

interface Operation
{
    public function getData(): array;
    public function execute(): OperationResult;
}

interface ContentServiceInterface
{
    public function store(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function find(int $id): ?Content;
}