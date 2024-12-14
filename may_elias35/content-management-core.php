namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Content\Models\Content;
use App\Core\Content\Events\ContentEvent;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagementInterface 
{
    private Repository $repository;
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        Repository $repository,
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function create(array $data): Content 
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            $validated = $this->validator->validate($data, 'create');
            
            $content = DB::transaction(function() use ($validated) {
                $content = $this->repository->create($validated);
                $this->handleMediaAttachments($content, $validated['media'] ?? []);
                $this->updateCache($content);
                
                $this->events->dispatch(new ContentEvent('created', $content));
                return $content;
            });
            
            return $content;
        }, ['action' => 'content.create']);
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            $content = $this->repository->findOrFail($id);
            $validated = $this->validator->validate($data, 'update');
            
            DB::transaction(function() use ($content, $validated) {
                $content->update($validated);
                $this->handleMediaAttachments($content, $validated['media'] ?? []);
                $this->updateCache($content);
                
                $this->events->dispatch(new ContentEvent('updated', $content));
            });
            
            return $content;
        }, ['action' => 'content.update', 'resource' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            
            DB::transaction(function() use ($content) {
                $content->delete();
                $this->clearCache($content);
                
                $this->events->dispatch(new ContentEvent('deleted', $content));
            });
            
            return true;
        }, ['action' => 'content.delete', 'resource' => $id]);
    }

    public function publish(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            
            DB::transaction(function() use ($content) {
                $content->publish();
                $this->updateCache($content);
                
                $this->events->dispatch(new ContentEvent('published', $content));
            });
            
            return true;
        }, ['action' => 'content.publish', 'resource' => $id]);
    }

    public function version(int $id): ContentVersion
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            
            return DB::transaction(function() use ($content) {
                $version = $content->createVersion();
                $this->events->dispatch(new ContentEvent('versioned', $content));
                return $version;
            });
        }, ['action' => 'content.version', 'resource' => $id]);
    }

    private function updateCache(Content $content): void
    {
        $key = "content.{$content->id}";
        $this->cache->put($key, $content, 3600);
        $this->cache->tags(['content'])->put("list.{$content->type}", $this->repository->getList($content->type), 3600);
    }

    private function clearCache(Content $content): void
    {
        $this->cache->forget("content.{$content->id}");
        $this->cache->tags(['content'])->flush();
    }

    private function handleMediaAttachments(Content $content, array $media): void
    {
        $content->media()->sync($media);
    }
}

class ValidationService
{
    private array $rules = [
        'create' => [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:post,page,article',
            'status' => 'required|string|in:draft,published',
            'media' => 'array',
            'media.*' => 'integer|exists:media,id'
        ],
        'update' => [
            'title' => 'string|max:255',
            'content' => 'string',
            'type' => 'string|in:post,page,article',
            'status' => 'string|in:draft,published',
            'media' => 'array',
            'media.*' => 'integer|exists:media,id'
        ]
    ];

    public function validate(array $data, string $operation): array
    {
        $validator = validator($data, $this->rules[$operation]);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }
}
