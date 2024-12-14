<?php
namespace App\Core\Services;

class ContentService {
    private ContentRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function create(array $data): Content {
        try {
            DB::beginTransaction();
            
            // Validate and secure data
            $this->security->validateAccess('content.create');
            $validated = $this->validator->validateContent($data);
            
            // Create content
            $content = $this->repository->create($validated);
            
            // Clear relevant caches
            $this->cache->invalidateTag('content_list');
            
            // Log success
            $this->logger->logSuccess('content.create', [
                'id' => $content->id,
                'type' => $content->type
            ]);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('content.create', $e);
            throw $e;
        }
    }

    public function update(int $id, array $data): Content {
        try {
            DB::beginTransaction();
            
            // Validate access and data
            $this->security->validateAccess("content.update.$id");
            $validated = $this->validator->validateUpdate($data);
            
            // Update content
            $content = $this->repository->update($id, $validated);
            
            // Clear caches
            $this->cache->invalidate("content.$id");
            $this->cache->invalidateTag('content_list');
            
            // Log success
            $this->logger->logSuccess('content.update', [
                'id' => $id,
                'changes' => array_keys($validated)
            ]);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('content.update', $e);
            throw $e;
        }
    }

    private function handleError(string $operation, \Exception $e): void {
        $this->logger->logError($operation, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($e instanceof QueryException) {
            throw new ServiceException(
                "Operation failed: Database error",
                0,
                $e
            );
        }
    }
}

class ServiceException extends \Exception {}
