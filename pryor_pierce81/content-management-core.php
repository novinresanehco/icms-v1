<?php

namespace App\Core\Content;

use App\Core\Security\CoreSecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Repository\BaseRepository;
use App\Core\Events\ContentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class ContentManager implements ContentManagementInterface
{
    private CoreSecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        CoreSecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository),
            new SecurityContext([
                'action' => 'content.create',
                'data' => $data
            ])
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository),
            new SecurityContext([
                'action' => 'content.update',
                'id' => $id,
                'data' => $data
            ])
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository),
            new SecurityContext([
                'action' => 'content.delete',
                'id' => $id
            ])
        );
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->repository),
            new SecurityContext([
                'action' => 'content.publish',
                'id' => $id
            ])
        );
    }

    public function versionContent(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id, $this->repository),
            new SecurityContext([
                'action' => 'content.version',
                'id' => $id
            ])
        );
    }
}

class ContentRepository extends BaseRepository
{
    protected $model = Content::class;

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey('content', $id),
            fn() => parent::find($id)
        );
    }

    public function create(array $data): Content
    {
        $content = DB::transaction(function() use ($data) {
            $content = $this->model::create($this->validate($data));
            $this->createVersion($content);
            return $content;
        });

        $this->cache->tags('content')->flush();
        return $content;
    }

    public function update(int $id, array $data): Content
    {
        $content = DB::transaction(function() use ($id, $data) {
            $content = $this->find($id);
            $content->update($this->validate($data));
            $this->createVersion($content);
            return $content;
        });

        $this->cache->tags('content')->flush();
        return $content;
    }

    protected function validate(array $data): array
    {
        return $this->validator->validate($data, [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id'
        ]);
    }

    protected function createVersion(Content $content): ContentVersion
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }
}

abstract class BaseContentOperation implements CriticalOperation
{
    protected array $data;
    protected ContentRepository $repository;

    public function __construct(array $data, ContentRepository $repository)
    {
        $this->data = $data;
        $this->repository = $repository;
    }

    abstract public function execute(): OperationResult;
    abstract public function getValidationRules(): array;
    abstract public function getRequiredPermissions(): array;
    abstract public function getRateLimitKey(): string;
    
    public function getData(): array
    {
        return $this->data;
    }
}

class CreateContentOperation extends BaseContentOperation
{
    public function execute(): OperationResult
    {
        $content = $this->repository->create($this->data);
        event(new ContentCreated($content));
        return new OperationResult($content);
    }

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }

    public function getRateLimitKey(): string
    {
        return 'content:create:' . auth()->id();
    }
}
