<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\CMSInterface;
use App\Core\Exceptions\{ContentException, ValidationException};
use Illuminate\Support\Facades\DB;

class ContentManager implements CMSInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private ContentRepository $repository;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        ContentRepository $repository,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    public function createContent(array $data, SecurityContext $context): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository),
            $context
        );
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository),
            $context
        );
    }

    public function getContent(int $id, SecurityContext $context): ContentEntity
    {
        return $this->cache->remember(
            "content.{$id}",
            fn() => $this->repository->findWithSecurity($id, $context)
        );
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository),
            $context
        );
    }

    public function publishContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->repository),
            $context
        );
    }

    public function versionContent(int $id, SecurityContext $context): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id, $this->repository),
            $context
        );
    }

    public function searchContent(array $criteria, SecurityContext $context): ContentCollection
    {
        return $this->cache->remember(
            $this->buildSearchCacheKey($criteria),
            fn() => $this->repository->search($criteria, $context)
        );
    }
}

class ContentRepository
{
    private DB $database;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function save(ContentEntity $content): ContentEntity
    {
        return DB::transaction(function() use ($content) {
            if ($content->isNew()) {
                $data = $this->database->table('contents')->insert($content->toArray());
            } else {
                $data = $this->database->table('contents')
                    ->where('id', $content->getId())
                    ->update($content->toArray());
            }

            $this->logger->logContentChange($content);
            return ContentEntity::fromArray($data);
        });
    }

    public function findWithSecurity(int $id, SecurityContext $context): ContentEntity
    {
        $content = $this->database->table('contents')
            ->where('id', $id)
            ->first();

        if (!$content) {
            throw new ContentException("Content not found: {$id}");
        }

        if (!$this->checkAccess($content, $context)) {
            throw new SecurityException("Access denied to content: {$id}");
        }

        return ContentEntity::fromArray($content);
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($id, $context) {
            $content = $this->findWithSecurity($id, $context);
            
            $this->database->table('contents')
                ->where('id', $id)
                ->delete();

            $this->logger->logContentDeletion($content);
            return true;
        });
    }

    public function createVersion(ContentEntity $content): ContentVersion
    {
        return DB::transaction(function() use ($content) {
            $version = new ContentVersion($content);
            
            $this->database->table('content_versions')->insert($version->toArray());
            
            $this->logger->logVersionCreation($version);
            return $version;
        });
    }

    public function search(array $criteria, SecurityContext $context): ContentCollection
    {
        $query = $this->database->table('contents');

        foreach ($criteria as $key => $value) {
            $query->where($key, $value);
        }

        $results = $query->get()
            ->filter(fn($content) => $this->checkAccess($content, $context))
            ->map(fn($data) => ContentEntity::fromArray($data));

        return new ContentCollection($results);
    }

    private function checkAccess($content, SecurityContext $context): bool
    {
        return true; // Implement actual access control logic
    }
}

class CreateContentOperation implements CriticalOperation
{
    private array $data;
    private ContentRepository $repository;

    public function __construct(array $data, ContentRepository $repository)
    {
        $this->data = $data;
        $this->repository = $repository;
    }

    public function execute(): OperationResult
    {
        $content = ContentEntity::create($this->data);
        $saved = $this->repository->save($content);
        return new OperationResult($saved);
    }

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }
}

class ContentEntity
{
    private ?int $id;
    private string $title;
    private string $content;
    private string $status;
    private int $authorId;
    private array $metadata;
    private ?DateTime $publishedAt;

    public static function create(array $data): self
    {
        $instance = new self();
        $instance->fill($data);
        return $instance;
    }

    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->fill($data);
        $instance->id = $data['id'];
        return $instance;
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status,
            'author_id' => $this->authorId,
            'metadata' => json_encode($this->metadata),
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s')
        ];
    }

    private function fill(array $data): void
    {
        $this->title = $data['title'];
        $this->content = $data['content'];
        $this->status = $data['status'];
        $this->authorId = $data['author_id'];
        $this->metadata = $data['metadata'] ?? [];
        $this->publishedAt = isset($data['published_at']) 
            ? new DateTime($data['published_at'])
            : null;
    }
}
