<?php

namespace App\Core\Services;

class CMSService implements ServiceInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private EventDispatcher $events;

    public function executeOperation(Operation $operation): Result 
    {
        return DB::transaction(function() use ($operation) {
            $context = $this->security->createContext($operation);
            
            $this->validateOperation($operation, $context);
            
            $result = match($operation->getType()) {
                OperationType::CREATE => $this->handleCreate($operation, $context),
                OperationType::UPDATE => $this->handleUpdate($operation, $context),
                OperationType::DELETE => $this->handleDelete($operation, $context),
                OperationType::QUERY => $this->handleQuery($operation, $context)
            };
            
            $this->cache->handleResult($operation, $result);
            $this->events->dispatch(new OperationComplete($operation, $result));
            
            return $result;
        });
    }

    protected function handleCreate(Operation $op, Context $ctx): Result 
    {
        $this->security->enforcePermissions($ctx, ['content.create']);
        $data = $this->repository->create($op->getData());
        $this->events->dispatch(new ContentCreated($data, $ctx));
        return new Result($data, ResultType::CREATED);
    }

    protected function handleUpdate(Operation $op, Context $ctx): Result 
    {
        $this->security->enforcePermissions($ctx, ['content.update']);
        $data = $this->repository->update($op->getId(), $op->getData());
        $this->events->dispatch(new ContentUpdated($data, $ctx));
        return new Result($data, ResultType::UPDATED);
    }

    protected function handleDelete(Operation $op, Context $ctx): Result 
    {
        $this->security->enforcePermissions($ctx, ['content.delete']);
        $data = $this->repository->delete($op->getId());
        $this->events->dispatch(new ContentDeleted($op->getId(), $ctx));
        return new Result($data, ResultType::DELETED);
    }

    protected function handleQuery(Operation $op, Context $ctx): Result 
    {
        $this->security->enforcePermissions($ctx, ['content.read']);
        $data = $this->repository->query($op->getCriteria());
        return new Result($data, ResultType::RETRIEVED);
    }
}