<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\{SecurityManager, ValidationService};

class ContentManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditSystem $audit;
    private array $validContentTypes = ['page', 'post', 'media', 'template'];

    public function createContent(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $this->security->validateAccess('content', 'create');
            $validated = $this->validator->validateInput($data, $this->getContentRules());
            
            $content = Content::create([
                'title' => $validated['title'],
                'type' => $validated['type'],
                'content' => $this->processContent($validated['content']),
                'meta' => $this->processMeta($validated['meta'] ?? []),
                'status' => ContentStatus::DRAFT,
                'created_by' => auth()->id(),
                'version' => 1,
                'hash' => $this->generateContentHash($validated)
            ]);

            $this->cache->invalidateContentCache($content->type);
            $this->audit->logContentCreation($content);
            
            return $content;
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->findOrFail($id);
            $this->security->validateAccess('content', 'update');
            
            $validated = $this->validator->validateInput($data, $this->getContentRules());
            
            $newVersion = $content->version + 1;
            $this->archiveVersion($content);
            
            $content->update([
                'title' => $validated['title'],
                'content' => $this->processContent($validated['content']),
                'meta' => $this->processMeta($validated['meta'] ?? []),
                'updated_by' => auth()->id(),
                'version' => $newVersion,
                'hash' => $this->generateContentHash($validated)
            ]);

            $this->cache->invalidateContentCache($content->type);
            $this->audit->logContentUpdate($content);
            
            return $content;
        });
    }

    public function publishContent(int $id): Content
    {
        return DB::transaction(function() use ($id) {
            $content = $this->findOrFail($id);
            $this->security->validateAccess('content', 'publish');
            
            $content->update([
                'status' => ContentStatus::PUBLISHED,
                'published_at' => now(),
                'published_by' => auth()->id()
            ]);

            $this->cache->invalidateContentCache($content->type);
            $this->audit->logContentPublication($content);
            
            return $content;
        });
    }

    public function getContent(int $id): Content
    {
        return Cache::remember(
            $this->getContentCacheKey($id),
            $this->getCacheDuration(),
            function() use ($id) {
                $content = $this->findOrFail($id);
                $this->security->validateAccess('content', 'read');
                return $content;
            }
        );
    }

    public function listContent(array $filters = []): Collection
    {
        $cacheKey = $this->generateListCacheKey($filters);
        
        return Cache::remember($cacheKey, $this->getCacheDuration(), function() use ($filters) {
            $this->security->validateAccess('content', 'list');
            
            $query = Content::query();
            
            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            return $query->paginate($filters['per_page'] ?? 15);
        });
    }

    private function findOrFail(int $id): Content
    {
        $content = Content::find($id);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }
        
        return $content;
    }

    private function processContent(array $content): array
    {
        foreach ($content as &$block) {
            $block = $this->sanitizeBlock($block);
            $block['hash'] = $this->generateBlockHash($block);
        }
        
        return $content;
    }

    private function sanitizeBlock(array $block): array
    {
        $block['content'] = strip_tags(
            $block['content'], 
            $this->config->getAllowedHtmlTags()
        );
        
        if (isset($block['meta'])) {
            $block['meta'] = $this->sanitizeMeta($block['meta']);
        }
        
        return $block;
    }

    private function processMeta(array $meta): array
    {
        return array_intersect_key(
            $meta,
            array_flip($this->config->getAllowedMetaFields())
        );
    }

    private function generateContentHash(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            $this->config->getSecurityKey()
        );
    }

    private function generateBlockHash(array $block): string
    {
        return hash_hmac(
            'sha256',
            json_encode($block),
            $this->config->getSecurityKey()
        );
    }

    private function archiveVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'version' => $content->version,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }

    private function getContentCacheKey(int $id): string
    {
        return "content:{$id}";
    }

    private function generateListCacheKey(array $filters): string
    {
        return 'content:list:' . hash('sha256', serialize($filters));
    }

    private function getCacheDuration(): int
    {
        return $this->config->getContentCacheDuration();
    }

    private function getContentRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in($this->validContentTypes)],
            'content' => ['required', 'array'],
            'meta' => ['sometimes', 'array']
        ];
    }
}
