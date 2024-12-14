<?php

namespace App\Core\Operations;

abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $audit;
    
    abstract public function execute(): Result;
    abstract public function validate(): bool;
    abstract public function getRequiredPermission(): string;
}

class StoreOperation extends CriticalOperation
{
    private Repository $repository;
    private array $data;

    public function execute(): Result
    {
        return $this->repository->store($this->data);
    }

    public function validate(): bool
    {
        return $this->validator->validate($this->data);
    }

    public function getRequiredPermission(): string 
    {
        return 'store';
    }
}

class UpdateOperation extends CriticalOperation 
{
    private Repository $repository;
    private string $id;
    private array $data;

    public function execute(): Result
    {
        return $this->repository->update($this->id, $this->data);
    }

    public function validate(): bool
    {
        return $this->validator->validate($this->data);
    }

    public function getRequiredPermission(): string
    {
        return 'update';
    }
}

class DeleteOperation extends CriticalOperation
{
    private Repository $repository;
    private string $id;

    public function execute(): Result
    {
        return $this->repository->delete($this->id);
    }

    public function validate(): bool
    {
        return $this->validator->validateId($this->id);
    }

    public function getRequiredPermission(): string
    {
        return 'delete'; 
    }
}

class PublishOperation extends CriticalOperation
{
    private ContentRepository $repository;
    private string $id;

    public function execute(): Result
    {
        $content = $this->repository->find($this->id);
        
        if (!$content) {
            throw new NotFoundException('Content not found');
        }
        
        return $this->repository->publish($content);
    }

    public function validate(): bool
    {
        return $this->validator->validateId($this->id);
    }

    public function getRequiredPermission(): string
    {
        return 'publish';
    }
}

namespace App\Core\Repository;

abstract class CriticalRepository
{
    protected ValidationService $validator;
    protected SecurityManager $security;
    protected CacheManager $cache;

    abstract protected function performStore(array $data): Result;
    abstract protected function performUpdate(string $id, array $data): Result;
    abstract protected function performDelete(string $id): Result;

    public function store(array $data): Result
    {
        if (!$this->validator->validate($data)) {
            throw new ValidationException('Invalid data');
        }

        return DB::transaction(function() use ($data) {
            return $this->performStore($data);
        });
    }

    public function update(string $id, array $data): Result
    {
        if (!$this->validator->validate($data)) {
            throw new ValidationException('Invalid data');
        }

        return DB::transaction(function() use ($id, $data) {
            return $this->performUpdate($id, $data);
        });
    }

    public function delete(string $id): Result
    {
        if (!$this->validator->validateId($id)) {
            throw new ValidationException('Invalid ID');
        }

        return DB::transaction(function() use ($id) {
            return $this->performDelete($id);
        });
    }
}

namespace App\Core\Content;

class ContentRepository extends CriticalRepository
{
    protected function performStore(array $data): Content
    {
        return Content::create($data);
    }

    protected function performUpdate(string $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        $content->update($data);
        return $content;
    }

    protected function performDelete(string $id): bool
    {
        return Content::destroy($id) > 0;
    }

    public function publish(Content $content): bool
    {
        $content->published = true;
        $content->published_at = now();
        return $content->save();
    }
}