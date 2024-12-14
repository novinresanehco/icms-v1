namespace App\Core\CMS;

class ContentManager implements ContentManagementInterface
{
    private Repository $repository;
    private CacheManager $cache;
    private SecurityManager $security;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, ['content.create'])
        )->getData();
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, ['content.update'])
        )->getData();
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, ['content.publish'])
        )->getData();
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, ['content.delete'])
        )->getData();
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->repository->find($id);
        });
    }
}

class Content extends Model
{
    protected $casts = [
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft'
    ];

    public function version(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function createVersion(): ContentVersion
    {
        return $this->version()->create([
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta,
            'version' => $this->version()->count() + 1
        ]);
    }
}

class ContentRepository implements Repository 
{
    private Content $model;

    public function store(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model->create($data);
            $content->createVersion();
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->model->findOrFail($id);
            $content->update($data);
            $content->createVersion();
            return $content->fresh();
        });
    }
}

class CreateContentOperation extends CriticalOperation
{
    public function execute(): OperationResult
    {
        if (!$this->validate()) {
            throw new ValidationException('Invalid content data');
        }

        $content = app(ContentRepository::class)->store($this->data);
        
        event(new ContentCreated($content));
        Cache::tags(['content'])->flush();
        
        return new OperationResult($content);
    }

    public function validate(): bool
    {
        return validator($this->data, [
            'title' => 'required|max:255',
            'body' => 'required',
            'type' => 'required|in:page,post,article',
            'status' => 'required|in:draft,published,archived',
            'meta' => 'array'
        ])->passes();
    }
}

class UpdateContentOperation extends CriticalOperation
{
    private int $id;

    public function __construct(int $id, array $data, array $permissions)
    {
        parent::__construct($data, $permissions);
        $this->id = $id;
    }

    public function execute(): OperationResult
    {
        if (!$this->validate()) {
            throw new ValidationException('Invalid content data');
        }

        $content = app(ContentRepository::class)->update($this->id, $this->data);
        
        event(new ContentUpdated($content));
        Cache::tags(['content'])->flush();
        
        return new OperationResult($content);
    }

    public function validate(): bool
    {
        return validator($this->data, [
            'title' => 'sometimes|max:255',
            'body' => 'sometimes',
            'type' => 'sometimes|in:page,post,article',
            'status' => 'sometimes|in:draft,published,archived',
            'meta' => 'sometimes|array'
        ])->passes();
    }
}

class PublishContentOperation extends CriticalOperation
{
    private int $id;

    public function execute(): OperationResult
    {
        $content = app(ContentRepository::class)->find($this->id);
        
        if (!$content) {
            throw new NotFoundException('Content not found');
        }

        $content->status = 'published';
        $content->published_at = now();
        $content->save();
        
        event(new ContentPublished($content));
        Cache::tags(['content'])->flush();
        
        return new OperationResult(true);
    }

    public function validate(): bool
    {
        return true;
    }
}

class ContentCacheManager
{
    private CacheStore $store;
    private int $ttl;

    public function get(string $key): ?Content
    {
        return $this->store->tags(['content'])->get($key);
    }

    public function put(string $key, Content $content): void
    {
        $this->store->tags(['content'])->put($key, $content, $this->ttl);
    }

    public function forget(string $key): void
    {
        $this->store->tags(['content'])->forget($key);
    }

    public function flush(): void
    {
        $this->store->tags(['content'])->flush();
    }
}

interface ContentManagementInterface
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function publish(int $id): bool;
    public function delete(int $id): bool;
    public function find(int $id): ?Content;
}
