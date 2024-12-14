<?php

namespace App\Core\CMS;

class ContentManagementSystem implements CMSInterface 
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected AuditLogger $logger;
    protected MetricsCollector $metrics;

    public function createContent(ContentRequest $request): ContentResponse
    {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            $this->validateCreateRequest($request);
            $this->checkRateLimit($request->getUserId(), 'create');
            
            $content = $this->executeCreate($request);
            $this->validateCreatedContent($content);
            
            DB::commit();
            $this->logSuccess('create', $content, $startTime);
            
            return new ContentResponse($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $request, 'create');
            throw new CMSException('Content creation failed', 0, $e);
        }
    }

    public function updateContent(int $id, ContentRequest $request): ContentResponse
    {
        DB::beginTransaction();
        
        try {
            $content = $this->findContent($id);
            $this->validateUpdateRequest($request, $content);
            $this->validatePermissions($request->getUserId(), $content);
            
            $updatedContent = $this->executeUpdate($content, $request);
            $this->cache->forget("content:$id");
            
            DB::commit();
            $this->logger->logUpdate($updatedContent, $request);
            
            return new ContentResponse($updatedContent);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $request, 'update');
            throw new CMSException('Content update failed', 0, $e);
        }
    }

    public function deleteContent(int $id, DeleteRequest $request): bool
    {
        DB::beginTransaction();
        
        try {
            $content = $this->findContent($id);
            $this->validateDeleteRequest($request, $content);
            $this->validatePermissions($request->getUserId(), $content);
            
            $this->executeDelete($content);
            $this->cache->forget("content:$id");
            
            DB::commit();
            $this->logger->logDeletion($content, $request);
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $request, 'delete');
            throw new CMSException('Content deletion failed', 0, $e);
        }
    }

    public function getContent(int $id, ReadRequest $request): ContentResponse
    {
        try {
            $content = $this->cache->remember(
                "content:$id",
                3600,
                fn() => $this->findAndValidateContent($id, $request)
            );
            
            $this->logAccess($content, $request);
            return new ContentResponse($content);

        } catch (\Exception $e) {
            $this->handleFailure($e, $request, 'read');
            throw new CMSException('Content retrieval failed', 0, $e);
        }
    }

    protected function validateCreateRequest(ContentRequest $request): void
    {
        if (!$this->validator->validate($request->getData(), [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'type' => 'required|string'
        ])) {
            throw new ValidationException('Invalid content data');
        }

        $this->security->validateUserAccess(
            $request->getUserId(),
            'content.create'
        );
    }

    protected function validateUpdateRequest(ContentRequest $request, Content $content): void
    {
        if (!$this->validator->validate($request->getData(), [
            'title' => 'string|max:200',
            'body' => 'string',
            'status' => 'in:draft,published'
        ])) {
            throw new ValidationException('Invalid update data');
        }

        if (!$content->canBeUpdatedBy($request->getUserId())) {
            throw new AuthorizationException('Unauthorized update attempt');
        }
    }

    protected function validateDeleteRequest(DeleteRequest $request, Content $content): void
    {
        if (!$content->canBeDeletedBy($request->getUserId())) {
            throw new AuthorizationException('Unauthorized deletion attempt');
        }
    }

    protected function executeCreate(ContentRequest $request): Content
    {
        $content = new Content($request->getData());
        $content->user_id = $request->getUserId();
        $content->save();
        
        $this->processMedia($content, $request->getMedia());
        $this->updateTags($content, $request->getTags());
        
        return $content;
    }

    protected function executeUpdate(Content $content, ContentRequest $request): Content
    {
        $content->update($request->getData());
        
        if ($request->hasMedia()) {
            $this->processMedia($content, $request->getMedia());
        }
        
        if ($request->hasTags()) {
            $this->updateTags($content, $request->getTags());
        }
        
        return $content;
    }

    protected function executeDelete(Content $content): void
    {
        $this->deleteMedia($content);
        $this->deleteTags($content);
        $content->delete();
    }

    protected function findAndValidateContent(int $id, ReadRequest $request): Content
    {
        $content = $this->findContent($id);
        
        if (!$content->isAccessibleBy($request->getUserId())) {
            throw new AuthorizationException('Unauthorized access attempt');
        }
        
        return $content;
    }

    protected function checkRateLimit(int $userId, string $operation): void
    {
        $key = "rate_limit:$userId:$operation";
        
        if ($this->cache->increment($key, 1, 3600) > $this->getRateLimit($operation)) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    protected function handleFailure(\Exception $e, Request $request, string $operation): void
    {
        $this->logger->logFailure($e, [
            'operation' => $operation,
            'user_id' => $request->getUserId(),
            'request_data' => $request->getData()
        ]);

        $this->metrics->increment("cms.failure.$operation");
    }
}
