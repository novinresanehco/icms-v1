<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditLogger $auditLogger,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function createContent(array $data, array $options = []): ContentResponse
    {
        return $this->security->executeSecureOperation(function() use ($data, $options) {
            // Validate input
            $this->validateContent($data);
            
            DB::beginTransaction();
            try {
                // Process content
                $content = $this->processContent($data);
                
                // Store content
                $content = $this->storeContent($content, $options);
                
                // Handle relationships
                $this->handleRelationships($content, $data);
                
                // Generate metadata
                $this->generateMetadata($content);
                
                DB::commit();
                
                // Clear relevant caches
                $this->invalidateCaches($content);
                
                return new ContentResponse($content);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to create content: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'content_create']);
    }

    public function updateContent(int $id, array $data, array $options = []): ContentResponse 
    {
        return $this->security->executeSecureOperation(function() use ($id, $data, $options) {
            // Validate update
            $this->validateUpdate($id, $data);
            
            DB::beginTransaction();
            try {
                // Get existing content
                $content = $this->findContent($id);
                
                // Create version
                $this->createVersion($content);
                
                // Update content
                $content = $this->performUpdate($content, $data, $options);
                
                // Update relationships
                $this->updateRelationships($content, $data);
                
                // Update metadata
                $this->updateMetadata($content);
                
                DB::commit();
                
                // Clear caches
                $this->invalidateCaches($content);
                
                return new ContentResponse($content);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to update content: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'content_update', 'content_id' => $id]);
    }

    public function publishContent(int $id): ContentResponse
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                // Get content
                $content = $this->findContent($id);
                
                // Validate publish state
                $this->validatePublish($content);
                
                // Create publish version
                $this->createPublishVersion($content);
                
                // Perform publish
                $content = $this->performPublish($content);
                
                // Update indexes
                $this->updateSearchIndexes($content);
                
                DB::commit();
                
                // Clear caches
                $this->invalidateCaches($content);
                
                return new ContentResponse($content);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to publish content: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'content_publish', 'content_id' => $id]);
    }

    private function validateContent(array $data): void
    {
        $validationRules = $this->getValidationRules();
        
        if (!$this->validator->validate($data, $validationRules)) {
            throw new ValidationException('Content validation failed');
        }
    }

    private function processContent(array $data): array
    {
        $processed = [];
        
        // Process basic fields
        foreach ($this->config['content_fields'] as $field) {
            if (isset($data[$field])) {
                $processed[$field] = $this->processField($field, $data[$field]);
            }
        }
        
        // Process custom fields
        if (isset($data['custom_fields'])) {
            $processed['custom_fields'] = $this->processCustomFields($data['custom_fields']);
        }
        
        return $processed;
    }

    private function storeContent(array $content, array $options): Content
    {
        // Create content record
        $model = new Content($content);
        $model->save();
        
        // Handle storage options
        if (!empty($options['storage'])) {
            $this->handleStorageOptions($model, $options['storage']);
        }
        
        return $model;
    }

    private function handleRelationships(Content $content, array $data): void
    {
        if (isset($data['relationships'])) {
            foreach ($data['relationships'] as $type => $items) {
                $this->processRelationship($content, $type, $items);
            }
        }
    }

    private function generateMetadata(Content $content): void
    {
        $metadata = [
            'checksum' => $this->calculateChecksum($content),
            'word_count' => $this->calculateWordCount($content),
            'reading_time' => $this->calculateReadingTime($content),
            'language' => $this->detectLanguage($content),
            'timestamps' => $this->generateTimestamps()
        ];
        
        $content->metadata()->create($metadata);
    }

    private function createVersion(Content $content): void
    {
        $version = new ContentVersion([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'metadata' => $content->metadata->toArray(),
            'created_by' => auth()->id()
        ]);
        
        $version->save();
    }

    private function performUpdate(Content $content, array $data, array $options): Content
    {
        // Update basic fields
        foreach ($this->config['content_fields'] as $field) {
            if (isset($data[$field])) {
                $content->$field = $this->processField($field, $data[$field]);
            }
        }
        
        // Update custom fields
        if (isset($data['custom_fields'])) {
            $content->custom_fields = $this->processCustomFields($data['custom_fields']);
        }
        
        $content->save();
        
        return $content;
    }

    private function validatePublish(Content $content): void
    {
        if (!$content->canBePublished()) {
            throw new ContentException('Content cannot be published');
        }
        
        $this->validatePublishRequirements($content);
    }

    private function createPublishVersion(Content $content): void
    {
        $version = new PublishedVersion([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'metadata' => $content->metadata->toArray(),
            'published_by' => auth()->id()
        ]);
        
        $version->save();
    }

    private function performPublish(Content $content): Content
    {
        $content->status = 'published';
        $content->published_at = now();
        $content->save();
        
        $this->auditLogger->logPublish($content);
        
        return $content;
    }

    private function updateSearchIndexes(Content $content): void
    {
        // Update search indexes
        $this->searchIndexer->index($content);
        
        // Update related indexes
        if ($content->hasRelatedContent()) {
            $this->updateRelatedIndexes($content);
        }
    }

    private function invalidateCaches(Content $content): void
    {
        $this->cache->invalidate([
            "content:{$content->id}",
            "content:list",
            "content:related:{$content->id}"
        ]);
        
        if ($content->hasCategories()) {
            $this->invalidateCategoryCaches($content);
        }
    }

    private function processField(string $field, $value)
    {
        return match($field) {
            'title' => $this->processTitle($value),
            'content' => $this->processContent($value),
            'excerpt' => $this->processExcerpt($value),
            default => $value
        };
    }

    private function processCustomFields(array $fields): array
    {
        $processed = [];
        
        foreach ($fields as $key => $value) {
            $processed[$key] = $this->processCustomField($key, $value);
        }
        
        return $processed;
    }

    private function validatePublishRequirements(Content $content): void
    {
        $requirements = $this->config['publish_requirements'];
        
        foreach ($requirements as $requirement) {
            if (!$this->checkRequirement($content, $requirement)) {
                throw new ValidationException("Publish requirement not met: {$requirement}");
            }
        }
    }
}
