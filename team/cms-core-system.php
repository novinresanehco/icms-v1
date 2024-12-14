<?php

namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $audit;
    private Repository $repository;

    public function createContent(array $data, SecurityContext $context): Content 
    {
        DB::beginTransaction();
        
        try {
            $validatedData = $this->validator->validateInput(
                $data,
                $this->getContentRules()
            );

            $this->security->validateAccess($context, 'content:create');
            
            $content = $this->repository->create(
                $validatedData,
                $context->getUserId()
            );
            
            $this->cache->invalidateContentCache($content->getId());
            $this->audit->logContentCreation($content, $context);
            
            DB::commit();
            return $content;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, $data);
            throw $e;
        }
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        DB::beginTransaction();
        
        try {
            $validatedData = $this->validator->validateInput(
                $data,
                $this->getContentRules()
            );

            $content = $this->getContent($id);
            $this->security->validateAccess($context, 'content:update', $content);
            
            $content->update($validatedData);
            $content->setLastModifiedBy($context->getUserId());
            
            $this->repository->save($content);
            $this->cache->invalidateContentCache($id);
            $this->audit->logContentUpdate($content, $context);
            
            DB::commit();
            return $content;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    public function publishContent(int $id, SecurityContext $context): bool 
    {
        DB::beginTransaction();
        
        try {
            $content = $this->getContent($id);
            $this->security->validateAccess($context, 'content:publish', $content);
            
            $this->validatePublishState($content);
            
            $content->setStatus(ContentStatus::PUBLISHED);
            $content->setPublishedAt(now());
            $content->setPublishedBy($context->getUserId());
            
            $this->repository->save($content);
            $this->cache->invalidateContentCache($id);
            $this->audit->logContentPublication($content, $context);
            
            DB::commit();
            return true;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        }
    }

    public function deleteContent(int $id, SecurityContext $context): bool 
    {
        DB::beginTransaction();
        
        try {
            $content = $this->getContent($id);
            $this->security->validateAccess($context, 'content:delete', $content);
            
            $this->repository->softDelete($content);
            $this->cache->invalidateContentCache($id);
            $this->audit->logContentDeletion($content, $context);
            
            DB::commit();
            return true;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        }
    }

    public function getContent(int $id, SecurityContext $context = null): Content 
    {
        try {
            $content = $this->cache->remember(
                "content.$id",
                fn() => $this->repository->findOrFail($id)
            );
            
            if ($context) {
                $this->security->validateAccess($context, 'content:read', $content);
            }
            
            return $content;
            
        } catch (Exception $e) {
            $this->handleFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        }
    }

    private function validatePublishState(Content $content): void 
    {
        if (!$content->isDraft()) {
            throw new InvalidStateException('Content must be in draft state to publish');
        }

        if (!$content->isComplete()) {
            throw new ValidationException('Content is incomplete');
        }

        foreach ($this->getPublishValidators() as $validator) {
            $validator->validate($content);
        }
    }

    private function handleFailure(Exception $e, string $operation, array $data): void 
    {
        $this->audit->logError($e, [
            'operation' => $operation,
            'data' => $data,
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->audit->logSecurityEvent(
                SecurityEvent::CONTENT_ACCESS_DENIED,
                $data
            );
        }
    }

    private function getContentRules(): array 
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'category_id' => ['required', 'exists:categories,id'],
            'tags' => ['array'],
            'meta' => ['array'],
            'publish_at' => ['date', 'after:now']
        ];
    }

    private function getPublishValidators(): array 
    {
        return [
            new ContentCompletionValidator(),
            new SecurityValidator(),
            new QualityValidator(),
            new SeoValidator()
        ];
    }
}
