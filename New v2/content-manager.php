<?php

namespace App\Core\Content;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use App\Core\State\StateManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface 
{
    private ValidationService $validator;
    private SecurityManager $security;
    private StateManager $state;
    private MetricsCollector $metrics;
    private ContentRepository $repository;
    private CacheManager $cache;

    public function __construct(
        ValidationService $validator,
        SecurityManager $security,
        StateManager $state,
        MetricsCollector $metrics,
        ContentRepository $repository,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->state = $state;
        $this->metrics = $metrics;
        $this->repository = $repository;
        $this->cache = $cache;
    }

    public function store(array $data, SecurityContext $context): Content
    {
        DB::beginTransaction();
        
        try {
            // Create state checkpoint
            $stateId = $this->state->captureState();
            
            // Validate content data
            $validated = $this->validator->validateCriticalData($data, [
                'title' => ['type' => 'string', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
                'status' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string', 'required' => true],
                'metadata' => ['type' => 'array', 'required' => false]
            ]);

            // Encrypt sensitive data
            $protected = $this->security->encryptData($validated);
            
            // Store content
            $content = $this->repository->create([
                'data' => $protected,
                'user_id' => $context->getUserId(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Clear related caches
            $this->cache->tags(['content'])->flush();
            
            // Record metrics
            $this->recordMetrics('content.create', $content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->state->restoreState($stateId);
            throw new ContentException('Failed to store content: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        DB::beginTransaction();
        
        try {
            // Verify access
            $content = $this->repository->findOrFail($id);
            $this->security->validateAccess($content, $context);

            // Create state checkpoint
            $stateId = $this->state->captureState();
            
            // Validate update data
            $validated = $this->validator->validateCriticalData($data, [
                'title' => ['type' => 'string', 'required' => false],
                'content' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false],
                'metadata' => ['type' => 'array', 'required' => false]
            ]);

            // Merge with existing data
            $existing = $this->security->decryptData($content->data);
            $merged = array_merge($existing, $validated);
            
            // Re-encrypt for storage
            $protected = $this->security->encryptData($merged);
            
            // Update content
            $content->update([
                'data' => $protected,
                'updated_at' => now()
            ]);

            // Clear caches
            $this->cache->tags(['content', "content:$id"])->flush();
            
            // Record metrics
            $this->recordMetrics('content.update', $content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->state->restoreState($stateId);
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function retrieve(int $id, SecurityContext $context): Content
    {
        return $this->cache->tags(['content', "content:$id"])
            ->remember("content:$id", 3600, function() use ($id, $context) {
                $content = $this->repository->findOrFail($id);
                $this->security->validateAccess($content, $context);
                
                $decrypted = $this->security->decryptData($content->data);
                $content->data = $decrypted;
                
                $this->recordMetrics('content.retrieve', $content);
                
                return $content;
            });
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        DB::beginTransaction();
        
        try {
            // Verify access
            $content = $this->repository->findOrFail($id);
            $this->security->validateAccess($content, $context);

            // Create state checkpoint
            $stateId = $this->state->captureState();
            
            // Perform soft delete
            $content->update([
                'deleted_at' => now(),
                'deleted_by' => $context->getUserId()
            ]);

            // Clear caches
            $this->cache->tags(['content', "content:$id"])->flush();
            
            // Record metrics
            $this->recordMetrics('content.delete', $content);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->state->restoreState($stateId);
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    protected function recordMetrics(string $operation, Content $content): void
    {
        $this->metrics->increment($operation, [
            'type' => $content->type,
            'status' => $content->status
        ]);

        $this->metrics->timing("$operation.time", microtime(true) - LARAVEL_START);
    }
}

interface ContentManagerInterface 
{
    public function store(array $data, SecurityContext $context): Content;
    public function update(int $id, array $data, SecurityContext $context): Content;
    public function retrieve(int $id, SecurityContext $context): Content;
    public function delete(int $id, SecurityContext $context): bool;
}

class Content extends Model
{
    protected $fillable = [
        'data',
        'user_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'deleted_by'
    ];

    protected $casts = [
        'data' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];
}

class ContentException extends \Exception {}
