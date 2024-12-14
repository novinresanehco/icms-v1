<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\ContentManagerInterface;
use App\Core\Services\{
    ValidationService,
    CacheManager,
    AuditService
};
use App\Core\Models\Content;
use App\Core\Exceptions\{
    ValidationException,
    ContentException
};

class ContentManager implements ContentManagerInterface
{
    protected ContentRepository $repository;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected AuditService $audit;

    public function __construct(
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        AuditService $audit
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function store(array $data): Content
    {
        DB::beginTransaction();
        
        try {
            // Validate input data
            $validated = $this->validator->validate($data);
            
            // Store content
            $content = $this->repository->create($validated);
            
            // Clear cache
            $this->cache->invalidate(['content']);
            
            // Log success
            $this->audit->logContentCreation($content);
            
            DB::commit();
            return $content;
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->audit->logValidationError($e, $data);
            throw $e;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->audit->logSystemError($e);
            throw new ContentException(
                'Content creation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        
        try {
            // Validate content exists
            $content = $this->repository->findOrFail($id);
            
            // Validate update data
            $validated = $this->validator->validate($data);
            
            // Update content
            $updated = $this->repository->update($content, $validated);
            
            // Clear cache
            $this->cache->invalidate(['content', "content.$id"]);
            
            // Log success 
            $this->audit->logContentUpdate($updated);
            
            DB::commit();
            return $updated;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->audit->logUpdateError($e, $id, $data);
            throw new ContentException(
                'Content update failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            // Validate content exists
            $content = $this->repository->findOrFail($id);
            
            // Delete content
            $this->repository->delete($content);
            
            // Clear cache
            $this->cache->invalidate(['content', "content.$id"]);
            
            // Log deletion
            $this->audit->logContentDeletion($content);
            
            DB::commit();
            return true;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->audit->logDeletionError($e, $id);
            throw new ContentException(
                'Content deletion failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
