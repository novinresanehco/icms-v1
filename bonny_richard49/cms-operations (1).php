<?php

namespace App\Core\Operations;

abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $audit;

    abstract public function execute(): Result;
    abstract public function validate(): bool;
    abstract public function getPermission(): string;
}

class CreateContentOperation extends CriticalOperation
{
    private array $data;
    private ContentRepository $repository;

    public function execute(): Result
    {
        return $this->repository->store($this->data);
    }

    public function validate(): bool
    {
        return $this->validator->validate($this->data, [
            'title' => 'required|string',
            'content' => 'required|string',
            'status' => 'boolean'
        ]);
    }

    public function getPermission(): string
    {
        return 'content.create';
    }
}

class UpdateContentOperation extends CriticalOperation
{
    private string $id;
    private array $data;
    private ContentRepository $repository;

    public function execute(): Result
    {
        $content = $this->repository->find($this->id);
        if (!$content) {
            throw new NotFoundException('Content not found');
        }
        return $this->repository->update($content, $this->data);
    }

    public function validate(): bool
    {
        return $this->validator->validate($this->data, [
            'title' => 'string',
            'content' => 'string',
            'status' => 'boolean'
        ]);
    }

    public function getPermission(): string
    {
        return 'content.update';
    }
}

class DeleteContentOperation extends CriticalOperation
{
    private string $id;
    private ContentRepository $repository;

    public function execute(): Result
    {
        $content = $this->repository->find($this->id);
        if (!$content) {
            throw new NotFoundException('Content not found');
        }
        return $this->repository->delete($content);
    }

    public function validate(): bool
    {
        return $this->validator->validate(['id' => $this->id], [
            'id' => 'required|string'
        ]);
    }

    public function getPermission(): string
    {
        return 'content.delete';
    }
}

class PublishContentOperation extends CriticalOperation
{
    private string $id;
    private ContentRepository $repository;

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
        return $this->validator->validate(['id' => $this->id], [
            'id' => 'required|string'
        ]);
    }

    public function getPermission(): string
    {
        return 'content.publish';
    }
}
