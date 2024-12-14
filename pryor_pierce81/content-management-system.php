<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Contracts\{ContentManagerInterface, ValidatorInterface};
use App\Core\Models\Content;
use App\Core\DTO\{ContentDTO, SecurityContext};

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidatorInterface $validator;
    private ContentRepository $repository;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        ValidatorInterface $validator,
        ContentRepository $repository,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    public function create(ContentDTO $content, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($content),
            $context
        );
    }

    public function update(int $id, ContentDTO $content, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $content),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            $context
        );
    }

    public function publish(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePublish($id),
            $context
        );
    }

    private function executeCreate(ContentDTO $content): Content 
    {
        $validated = $this->validator->validate($content->toArray());
        
        $result = DB::transaction(function() use ($validated) {
            $content = $this->repository->create($validated);
            $this->cache->invalidate(['content', 'list']);
            $this->auditLogger->logContentCreation($content);
            
            if ($content->hasMedia()) {
                $this->processMedia($content);
            }
            
            return $content;
        });

        return $result;
    }

    private function executeUpdate(int $id, ContentDTO $content): Content 
    {
        $this->validateContentExists($id);
        $validated = $this->validator->validate($content->toArray());
        
        $result = DB::transaction(function() use ($id, $validated) {
            $content = $this->repository->update($id, $validated);
            $this->cache->invalidateContentKeys($id);
            $this->auditLogger->logContentUpdate($content);
            
            if ($content->hasMedia()) {
                $this->processMedia($content);
            }
            
            return $content;
        });

        return $result;
    }

    private function executeDelete(int $id): bool 
    {
        $this->validateContentExists($id);
        
        return DB::transaction(function() use ($id) {
            $deleted = $this->repository->delete($id);
            $this->cache->invalidateContentKeys($id);
            $this->auditLogger->logContentDeletion($id);
            
            return $deleted;
        });
    }

    private function executePublish(int $id): bool 
    {
        $this->validateContentExists($id);
        
        return DB::transaction(function() use ($id) {
            $published = $this->repository->publish($id);
            $this->cache->invalidateContentKeys($id);
            $this->auditLogger->logContentPublication($id);
            
            return $published;
        });
    }

    private function validateContentExists(int $id): void 
    {
        if (!$this->repository->exists($id)) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }
    }

    private function processMedia(Content $content): void 
    {
        foreach ($content->getMedia() as $media) {
            $this->mediaProcessor->process($media);
        }
    }
}

class ContentRepository
{
    private Content $model;
    private CacheManager $cache;
    
    public function exists(int $id): bool 
    {
        return $this->cache->remember(
            "content.exists.{$id}",
            fn() => $this->model->where('id', $id)->exists()
        );
    }
    
    public function create(array $data): Content 
    {
        return $this->model->create($data);
    }
    
    public function update(int $id, array $data): Content 
    {
        $content = $this->model->findOrFail($id);
        $content->update($data);
        return $content;
    }
    
    public function delete(int $id): bool 
    {
        return $this->model->findOrFail($id)->delete();
    }
    
    public function publish(int $id): bool 
    {
        return $this->model->findOrFail($id)->update(['published' => true]);
    }
}
