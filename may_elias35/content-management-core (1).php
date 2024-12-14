<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function createContent(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data),
            $context
        );
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data),
            $context
        );
    }

    public function publishContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id),
            $context
        );
    }

    public function versionContent(int $id, SecurityContext $context): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id),
            $context
        );
    }

    private function validateContent(array $data): void
    {
        if (!$this->validator->validateContent($data)) {
            throw new ContentValidationException('Content validation failed');
        }
    }

    private function cacheContent(Content $content): void
    {
        $this->cache->store(
            $this->getCacheKey($content->getId()),
            $content,
            $this->config['cache_ttl']
        );
    }

    private function invalidateCache(int $contentId): void
    {
        $this->cache->invalidate($this->getCacheKey($contentId));
    }
}

class CreateContentOperation implements CriticalOperation
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function execute(): Content
    {
        DB::beginTransaction();
        
        try {
            $content = Content::create($this->data);
            $this->processMedia($content);
            $this->createRevision($content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentOperationException('Content creation failed', 0, $e);
        }
    }

    public function getType(): string
    {
        return 'create_content';
    }

    private function processMedia(Content $content): void
    {
        if (!empty($this->data['media'])) {
            foreach ($this->data['media'] as $media) {
                MediaProcessor::process($media, $content);
            }
        }
    }

    private function createRevision(Content $content): void
    {
        ContentRevision::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_at' => now()
        ]);
    }
}

class ContentVersion
{
    private int $id;
    private array $data;
    private string $checksum;
    private \DateTime $createdAt;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->checksum = $this->calculateChecksum($data);
        $this->createdAt = new \DateTime();
    }

    public function verify(): bool
    {
        return $this->checksum === $this->calculateChecksum($this->data);
    }

    private function calculateChecksum(array $data): string
    {
        return hash('sha256', json_encode($data));
    }
}

interface ContentManagerInterface
{
    public function createContent(array $data, SecurityContext $context): Content;
    public function updateContent(int $id, array $data, SecurityContext $context): Content;
    public function publishContent(int $id, SecurityContext $context): bool;
    public function versionContent(int $id, SecurityContext $context): ContentVersion;
}
