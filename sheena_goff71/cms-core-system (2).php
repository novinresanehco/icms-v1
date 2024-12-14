<?php

namespace App\Core;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;

class CMSCore
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    /**
     * Execute critical CMS operation with comprehensive protection
     */
    public function executeCriticalOperation(string $operation, array $data): mixed
    {
        // Start monitoring and validation
        $monitoringId = $this->monitor->startOperation($operation);
        $this->validator->validateOperation($operation, $data);

        DB::beginTransaction();
        
        try {
            // Execute with security controls
            $result = $this->security->executeProtected(function() use ($operation, $data) {
                return $this->processCMSOperation($operation, $data);
            });

            // Validate result before commit
            $this->validator->validateResult($operation, $result);
            
            DB::commit();
            $this->monitor->recordSuccess($monitoringId);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleOperationFailure($e, $operation, $data);
            throw $e;
        }
    }

    /**
     * Core CMS operations with caching and validation
     */
    private function processCMSOperation(string $operation, array $data): mixed
    {
        return match($operation) {
            'content.create' => $this->createContent($data),
            'content.update' => $this->updateContent($data),
            'media.upload' => $this->handleMediaUpload($data),
            'category.manage' => $this->manageCategory($data),
            default => throw new \InvalidArgumentException('Invalid operation')
        };
    }

    private function createContent(array $data): array
    {
        $validated = $this->validator->validateContent($data);
        
        // Process with auditability
        $content = DB::table('cms_content')->insertGetId([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'status' => $validated['status'],
            'created_at' => now(),
            'created_by' => auth()->id()
        ]);

        // Invalidate relevant caches
        Cache::tags(['content'])->flush();

        return ['id' => $content, 'status' => 'created'];
    }

    private function updateContent(array $data): array
    {
        $validated = $this->validator->validateContent($data);
        
        DB::table('cms_content')
            ->where('id', $validated['id'])
            ->update([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'status' => $validated['status'],
                'updated_at' => now(),
                'updated_by' => auth()->id()
            ]);

        Cache::tags(['content'])->flush();

        return ['id' => $validated['id'], 'status' => 'updated'];
    }

    private function handleMediaUpload(array $data): array
    {
        $validated = $this->validator->validateMedia($data);

        // Secure media handling
        $path = $validated['file']->store('cms/media', 'secure');
        
        $media = DB::table('cms_media')->insertGetId([
            'path' => $path,
            'type' => $validated['file']->getMimeType(),
            'size' => $validated['file']->getSize(),
            'created_at' => now(),
            'created_by' => auth()->id()
        ]);

        return ['id' => $media, 'path' => $path];
    }

    private function manageCategory(array $data): array
    {
        $validated = $this->validator->validateCategory($data);

        $category = DB::table('cms_categories')
            ->updateOrInsert(
                ['id' => $validated['id'] ?? null],
                [
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'parent_id' => $validated['parent_id'] ?? null,
                    'updated_at' => now(),
                    'updated_by' => auth()->id()
                ]
            );

        Cache::tags(['categories'])->flush();

        return ['status' => 'success', 'data' => $category];
    }

    private function handleOperationFailure(\Throwable $e, string $operation, array $data): void
    {
        Log::critical('CMS Operation Failed', [
            'operation' => $operation,
            'data' => $data,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Implement additional failure handling (notifications, recovery, etc)
    }
}
