// app/Core/CMS/ContentManager.php
<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityKernel;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Models\Content;

class ContentManager implements ContentManagerInterface
{
    private SecurityKernel $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function createContent(array $data): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeContentCreation($data),
            ['action' => 'content_create', 'data' => $data]
        );
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeContentUpdate($id, $data),
            ['action' => 'content_update', 'id' => $id, 'data' => $data]
        );
    }

    public function publishContent(int $id): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeContentPublication($id),
            ['action' => 'content_publish', 'id' => $id]
        );
    }

    private function executeContentCreation(array $data): Content
    {
        // Validate content data
        $validated = $this->validator->validateContent($data);
        
        DB::beginTransaction();
        
        try {
            // Create content
            $content = Content::create($validated);
            
            // Process media attachments
            if (!empty($validated['media'])) {
                $this->processMediaAttachments($content, $validated['media']);
            }
            
            // Generate metadata
            $this->generateContentMetadata($content);
            
            // Clear relevant caches
            $this->clearContentCaches('create');
            
            // Record metrics
            $this->recordContentMetrics('create', $content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentCreationException('Content creation failed', 0, $e);
        }
    }

    private function executeContentUpdate(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        
        // Validate update data
        $validated = $this->validator->validateContentUpdate($data);
        
        DB::beginTransaction();
        
        try {
            // Create revision
            $this->createContentRevision($content);
            
            // Update content
            $content->update($validated);
            
            // Update media attachments
            if (isset($validated['media'])) {
                $this->updateMediaAttachments($content, $validated['media']);
            }
            
            // Update metadata
            $this->updateContentMetadata($content);
            
            // Clear relevant caches
            $this->clearContentCaches('update', $id);
            
            // Record metrics
            $this->recordContentMetrics('update', $content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentUpdateException('Content update failed', 0, $e);
        }
    }

    private function executeContentPublication(int $id): Content
    {
        $content = Content::findOrFail($id);
        
        DB::beginTransaction();
        
        try {
            // Validate content for publication
            $this->validateForPublication($content);
            
            // Update publication status
            $content->update(['status' => 'published', 'published_at' => now()]);
            
            // Generate publication metadata
            $this->generatePublicationMetadata($content);
            
            // Clear relevant caches
            $this->clearContentCaches('publish', $id);
            
            // Record metrics
            $this->recordContentMetrics('publish', $content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentPublicationException('Content publication failed', 0, $e);
        }
    }

    private function validateForPublication(Content $content): void
    {
        if (!$this->validator->validateForPublication($content)) {
            throw new ValidationException('Content failed publication validation');
        }
    }

    private function clearContentCaches(string $action, ?int $id = null): void
    {
        $tags = ['content'];
        
        if ($id) {
            $tags[] = "content:{$id}";
        }
        
        $this->cache->tags($tags)->flush();
    }

    private function recordContentMetrics(string $action, Content $content): void
    {
        $this->metrics->record("content.{$action}", [
            'content_id' => $content->id,
            'content_type' => $content->type,
            'user_id' => auth()->id(),
            'execution_time' => microtime(true) - LARAVEL_START
        ]);
    }
}