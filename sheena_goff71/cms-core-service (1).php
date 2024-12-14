<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Repositories\{ContentRepository, MediaRepository};
use App\Core\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use App\Core\Exceptions\{CMSException, ValidationException};

class CMSService implements CMSServiceInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentRepository $content;
    private MediaRepository $media;
    private AuditService $audit;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ContentRepository $content,
        MediaRepository $media,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->content = $content;
        $this->media = $media;
        $this->audit = $audit;
    }

    public function createContent(array $data, SecurityContext $context): Content
    {
        return $this->executeSecureOperation(function() use ($data, $context) {
            // Validate content data
            $validatedData = $this->validator->validate($data, [
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'required|in:draft,published',
                'category_id' => 'required|exists:categories,id',
                'tags' => 'array',
                'meta' => 'array'
            ]);

            // Store content with transaction protection
            DB::beginTransaction();
            
            try {
                // Create content
                $content = $this->content->create($validatedData);

                // Process media attachments if any
                if (!empty($data['media'])) {
                    $this->processMediaAttachments($content, $data['media']);
                }

                // Generate metadata
                $this->generateMetadata($content);

                // Update search index
                $this->updateSearchIndex($content);

                // Cache management
                $this->invalidateRelatedCache($content);

                DB::commit();

                // Fire events
                event(new ContentCreated($content, $context));

                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new CMSException('Content creation failed: ' . $e->getMessage(), 0, $e);
            }
        }, $context);
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        return $this->executeSecureOperation(function() use ($id, $data, $context) {
            $content = $this->content->findOrFail($id);
            
            // Version control
            $this->createContentVersion($content);

            // Update content
            $updatedContent = DB::transaction(function() use ($content, $data) {
                $content->update($this->validator->validate($data));
                
                if (!empty($data['media'])) {
                    $this->processMediaAttachments($content, $data['media']);
                }

                $this->generateMetadata($content);
                $this->updateSearchIndex($content);
                $this->invalidateRelatedCache($content);

                return $content->fresh();
            });

            event(new ContentUpdated($updatedContent, $context));

            return $updatedContent;
        }, $context);
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        return $this->executeSecureOperation(function() use ($id, $context) {
            return DB::transaction(function() use ($id, $context) {
                $content = $this->content->findOrFail($id);
                
                // Archive content
                $this->archiveContent($content);
                
                // Remove from indexes
                $this->removeFromSearchIndex($content);
                $this->invalidateRelatedCache($content);
                
                // Delete content
                $result = $content->delete();
                
                event(new ContentDeleted($content, $context));
                
                return $result;
            });
        }, $context);
    }

    private function executeSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        try {
            // Security validation
            $this->security->validateOperation([], $context);
            
            // Performance monitoring start
            $startTime = microtime(true);
            
            // Execute operation
            $result = $operation();
            
            // Log performance metrics
            $this->logPerformanceMetrics($startTime);
            
            return $result;

        } catch (\Exception $e) {
            $this->handleOperationFailure($e, $context);
            throw $e;
        }
    }

    private function processMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $mediaItem) {
            $this->media->process($mediaItem);
            $content->media()->attach($mediaItem['id'], [
                'type' => $mediaItem['type'],
                'position' => $mediaItem['position'] ?? null
            ]);
        }
    }

    private function generateMetadata(Content $content): void
    {
        $content->metadata()->updateOrCreate(
            ['content_id' => $content->id],
            [
                'meta_title' => $content->title,
                'meta_description' => substr(strip_tags($content->content), 0, 160),
                'word_count' => str_word_count(strip_tags($content->content)),
                'reading_time' => ceil(str_word_count(strip_tags($content->content)) / 200)
            ]
        );
    }

    private function updateSearchIndex(Content $content): void
    {
        // Update search index implementation
        SearchIndex::updateDocument($content);
    }

    private function invalidateRelatedCache(Content $content): void
    {
        $cacheKeys = [
            "content.{$content->id}",
            "content.list",
            "category.{$content->category_id}"
        ];

        foreach ($cacheKeys as $key) {
            Cache::tags(['content'])->forget($key);
        }
    }

    private function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id(),
            'version' => $content->version + 1
        ]);
    }

    private function archiveContent(Content $content): void
    {
        ContentArchive::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'archived_by' => auth()->id(),
            'archived_at' => now()
        ]);
    }

    private function logPerformanceMetrics(float $startTime): void
    {
        $executionTime = microtime(true) - $startTime;
        
        Log::info('CMS Operation Performance', [
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
    }

    private function handleOperationFailure(\Exception $e, SecurityContext $context): void
    {
        $this->audit->logFailure($e, $context, [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
