<?php

namespace App\Core\Tags;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class TagManager implements TagManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private CacheManager $cache;
    private array $config;

    private const MAX_TAG_LENGTH = 64;
    private const MAX_TAG_RELATIONSHIPS = 1000;

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

    public function createTag(array $data): TagResponse
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            $this->validateTagData($data);
            
            DB::beginTransaction();
            try {
                // Process tag data
                $processed = $this->processTagData($data);
                
                // Create tag record
                $tag = $this->storeTag($processed);
                
                // Handle taxonomies
                if (isset($data['taxonomies'])) {
                    $this->handleTaxonomies($tag, $data['taxonomies']);
                }
                
                // Generate metadata
                $this->generateMetadata($tag);
                
                DB::commit();
                
                // Invalidate relevant caches
                $this->invalidateTagCaches($tag);
                
                return new TagResponse($tag);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TagException('Failed to create tag: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'tag_create']);
    }

    public function attachTags(string $modelType, int $modelId, array $tags): TagResponse
    {
        return $this->security->executeSecureOperation(function() use ($modelType, $modelId, $tags) {
            $this->validateTagAttachment($modelType, $modelId, $tags);
            
            DB::beginTransaction();
            try {
                // Get or create tags
                $tagModels = $this->resolveTagModels($tags);
                
                // Get target model
                $model = $this->findModel($modelType, $modelId);
                
                // Attach tags
                $attachments = $this->performAttachment($model, $tagModels);
                
                // Update tag counts
                $this->updateTagCounts($tagModels);
                
                DB::commit();
                
                // Invalidate caches
                $this->invalidateModelTagCaches($model);
                
                return new TagResponse($attachments);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TagException('Failed to attach tags: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'tag_attach']);
    }

    private function validateTagData(array $data): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:' . self::MAX_TAG_LENGTH],
            'slug' => ['required', 'string', 'max:' . self::MAX_TAG_LENGTH, 'unique:tags'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'in:' . implode(',', $this->config['allowed_tag_types'])]
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Tag validation failed');
        }

        // Additional security validations
        $this->validateTagSecurity($data);
    }

    private function processTagData(array $data): array
    {
        return [
            'name' => $this->sanitizeTagName($data['name']),
            'slug' => $this->generateSlug($data['name']),
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'status' => TagStatus::ACTIVE,
            'metadata' => $this->generateTagMetadata($data)
        ];
    }

    private function storeTag(array $data): Tag
    {
        $tag = new Tag($data);
        $tag->save();
        
        $this->auditLogger->logTagCreation($tag);
        
        return $tag;
    }

    private function handleTaxonomies(Tag $tag, array $taxonomies): void
    {
        foreach ($taxonomies as $taxonomy) {
            $this->validateTaxonomy($taxonomy);
            $this->attachTaxonomy($tag, $taxonomy);
        }
    }

    private function validateTagAttachment(string $modelType, int $modelId, array $tags): void
    {
        if (!in_array($modelType, $this->config['taggable_models'])) {
            throw new ValidationException('Invalid model type for tagging');
        }

        if (count($tags) > self::MAX_TAG_RELATIONSHIPS) {
            throw new ValidationException('Exceeded maximum number of tag relationships');
        }

        foreach ($tags as $tag) {
            $this->validateTagFormat($tag);
        }
    }

    private function resolveTagModels(array $tags): array
    {
        $models = [];
        foreach ($tags as $tag) {
            $models[] = $this->findOrCreateTag($tag);
        }
        return $models;
    }

    private function performAttachment($model, array $tagModels): array
    {
        $attachments = [];
        foreach ($tagModels as $tag) {
            $attachments[] = $this->attachTag($model, $tag);
        }
        return $attachments;
    }

    private function attachTag($model, Tag $tag): TagAttachment
    {
        $attachment = new TagAttachment([
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'tag_id' => $tag->id,
            'created_by' => auth()->id()
        ]);
        
        $attachment->save();
        
        $this->auditLogger->logTagAttachment($attachment);
        
        return $attachment;
    }

    private function findOrCreateTag(string $tagName): Tag
    {
        $tag = Tag::where('name', $tagName)->first();
        
        if (!$tag) {
            $tag = $this->createTag(['name' => $tagName]);
        }
        
        return $tag;
    }

    private function sanitizeTagName(string $name): string
    {
        return htmlspecialchars(
            trim($name),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    private function validateTagSecurity(array $data): void
    {
        if (!$this->security->validateInput($data)) {
            throw new SecurityException('Tag data failed security validation');
        }
    }

    private function generateTagMetadata(array $data): array
    {
        return [
            'created_at' => now(),
            'created_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
    }

    private function invalidateTagCaches(Tag $tag): void
    {
        $this->cache->invalidate([
            "tag:{$tag->id}",
            "tag:{$tag->slug}",
            'tags:all',
            'tags:count'
        ]);
    }
}
