<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function create(array $data): Content
    {
        DB::beginTransaction();
        
        try {
            $validatedData = $this->validateContent($data);
            $secureData = $this->security->sanitizeContent($validatedData);
            
            $content = DB::table('contents')->insertGetId([
                'title' => $secureData['title'],
                'body' => $secureData['body'],
                'status' => ContentStatus::DRAFT,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'version' => 1
            ]);

            $this->auditLogger->logContentCreation($content);
            $this->cache->invalidateContentCache();
            
            DB::commit();
            return new Content($content);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditLogger->logContentError('creation', $e);
            throw new ContentException('Content creation failed', previous: $e);
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateAccess('content.update', $id);
            
            $validatedData = $this->validateContent($data);
            $secureData = $this->security->sanitizeContent($validatedData);
            
            $currentVersion = $this->getCurrentVersion($id);
            
            DB::table('content_versions')->insert([
                'content_id' => $id,
                'version' => $currentVersion + 1,
                'data' => json_encode($secureData),
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);
            
            DB::table('contents')
                ->where('id', $id)
                ->update([
                    'title' => $secureData['title'],
                    'body' => $secureData['body'],
                    'updated_at' => now(),
                    'version' => $currentVersion + 1
                ]);

            $this->auditLogger->logContentUpdate($id);
            $this->cache->invalidateContentCache($id);
            
            DB::commit();
            return new Content($id);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditLogger->logContentError('update', $e);
            throw new ContentException('Content update failed', previous: $e);
        }
    }

    public function publish(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateAccess('content.publish', $id);
            
            $content = DB::table('contents')
                ->where('id', $id)
                ->first();
                
            if (!$content) {
                throw new ContentException('Content not found');
            }
            
            if (!$this->validateForPublishing($content)) {
                throw new ContentException('Content not ready for publishing');
            }
            
            DB::table('contents')
                ->where('id', $id)
                ->update([
                    'status' => ContentStatus::PUBLISHED,
                    'published_at' => now()
                ]);

            $this->auditLogger->logContentPublish($id);
            $this->cache->invalidateContentCache($id);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditLogger->logContentError('publish', $e);
            throw new ContentException('Content publish failed', previous: $e);
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateAccess('content.delete', $id);
            
            DB::table('contents')
                ->where('id', $id)
                ->update([
                    'status' => ContentStatus::DELETED,
                    'deleted_at' => now(),
                    'deleted_by' => auth()->id()
                ]);

            $this->auditLogger->logContentDeletion($id);
            $this->cache->invalidateContentCache($id);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditLogger->logContentError('deletion', $e);
            throw new ContentException('Content deletion failed', previous: $e);
        }
    }

    private function validateContent(array $data): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'meta' => ['array'],
            'categories' => ['array'],
            'tags' => ['array']
        ];

        return $this->validator->validate($data, $rules);
    }

    private function getCurrentVersion(int $id): int
    {
        return DB::table('contents')
            ->where('id', $id)
            ->value('version') ?? 0;
    }

    private function validateForPublishing(object $content): bool
    {
        if (empty($content->title) || empty($content->body)) {
            return false;
        }

        if ($content->status === ContentStatus::DELETED) {
            return false;
        }

        return true;
    }
}
