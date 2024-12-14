<?php

namespace App\Core;

class SecurityManager implements SecurityManagerInterface 
{
    protected EncryptionService $encryption;
    protected AuditLogger $logger;
    protected AccessControl $access;

    public function __construct(
        EncryptionService $encryption,
        AuditLogger $logger, 
        AccessControl $access
    ) {
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->access = $access;
    }

    public function validateOperation(Operation $operation): bool
    {
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validateSecurity($operation);
            $this->logger->logOperation($operation);

            // Execute with monitoring
            $result = $operation->execute();
            
            // Validate result
            if (!$this->validateResult($result)) {
                throw new SecurityException('Operation result validation failed');
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailure($e);
            throw $e;
        }
    }

    protected function validateSecurity(Operation $operation): void
    {
        if (!$this->access->checkPermission($operation)) {
            throw new UnauthorizedException();
        }

        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            throw new IntegrityException();
        }
    }

    protected function validateResult($result): bool
    {
        return $this->encryption->verifyIntegrity($result);
    }
}

class ContentManager implements ContentManagerInterface
{
    protected Repository $repository;
    protected SecurityManager $security;
    protected CacheManager $cache;

    public function store(array $data): Content
    {
        $operation = new StoreContentOperation($data);
        
        if (!$this->security->validateOperation($operation)) {
            throw new SecurityException('Invalid store operation');
        }

        $content = $this->repository->store($data);
        $this->cache->invalidate(['content']);
        
        return $content;
    }

    public function update(int $id, array $data): Content
    {
        $operation = new UpdateContentOperation($id, $data);
        
        if (!$this->security->validateOperation($operation)) {
            throw new SecurityException('Invalid update operation');
        }

        $content = $this->repository->update($id, $data);
        $this->cache->invalidate(['content', $id]);
        
        return $content;
    }
}

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected ValidationService $validator;

    public function find(int $id): ?Model
    {
        return $this->cache->remember("model.$id", function() use ($id) {
            return $this->model->find($id);
        });
    }

    public function store(array $data): Model
    {
        $validated = $this->validator->validate($data);
        
        return DB::transaction(function() use ($validated) {
            return $this->model->create($validated);
        });
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->find($id);
        $validated = $this->validator->validate($data);
        
        return DB::transaction(function() use ($model, $validated) {
            $model->update($validated);
            return $model->fresh();
        });
    }
}

class TemplateManager implements TemplateManagerInterface
{
    protected TemplateRepository $repository;
    protected SecurityManager $security;
    protected CacheManager $cache;

    public function render(string $template, array $data = []): string
    {
        $operation = new RenderTemplateOperation($template, $data);
        
        if (!$this->security->validateOperation($operation)) {
            throw new SecurityException('Invalid render operation');
        }

        return $this->cache->remember("template.$template", function() use ($template, $data) {
            return $this->repository->render($template, $data);
        });
    }
}
