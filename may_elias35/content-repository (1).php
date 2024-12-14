namespace App\Core\Repositories;

use App\Core\Contracts\RepositoryInterface;
use App\Core\Cache\CacheManager;
use App\Models\Content;
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class ContentRepository implements RepositoryInterface
{
    protected Content $model;
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected array $config;

    public function __construct(
        Content $model,
        CacheManager $cache,
        SecurityManager $security,
        array $config
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->security = $security;
        $this->config = $config;
    }

    public function create(array $data): array
    {
        try {
            DB::beginTransaction();

            $content = $this->model->create([
                'title' => $data['title'],
                'content' => $data['content'],
                'metadata' => $this->processMetadata($data['metadata'] ?? []),
                'user_id' => $data['user_id'],
                'status' => $data['status'],
                'created_at' => $data['created_at'],
                'version' => $data['version']
            ]);

            $this->createVersion($content);
            
            DB::commit();

            return $content->toArray();
        } catch (QueryException $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to create content: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): array
    {
        try {
            DB::beginTransaction();

            $content = $this->model->findOrFail($id);
            
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'content' => $data['content'] ?? $content->content,
                'metadata' => $this->processMetadata($data['metadata'] ?? $content->metadata),
                'user_id' => $data['user_id'],
                'status' => $data['status'] ?? $content->status,
                'updated_at' => $data['updated_at'],
                'version' => $data['version']
            ]);

            $this->createVersion($content);
            
            DB::commit();

            $this->cache->forget($this->getCacheKey($id));

            return $content->toArray();
        } catch (QueryException $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function find(int $id): ?array
    {
        $cacheKey = $this->getCacheKey($id);

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $content = $this->model
            ->with(['user', 'versions'])
            ->find($id);

        if (!$content) {
            return null;
        }

        $result = $content->toArray();
        $this->cache->put($cacheKey, $result, $this->config['cache_ttl']);

        return $result;
    }

    public function getVersions(int $id): array
    {
        return $this->model
            ->findOrFail($id)
            ->versions()
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function softDelete(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $content = $this->model->findOrFail($id);
            
            $content->update([
                'deleted_by' => $data['deleted_by'],
                'deleted_at' => $data['deleted_at']
            ]);

            $this->createVersion($content);
            
            DB::commit();

            $this->cache->forget($this->getCacheKey($id));

            return true;
        } catch (QueryException $e) {
            DB::rollBack();
            throw new RepositoryException('Failed to delete content: ' . $e->getMessage());
        }
    }

    protected function createVersion(Content $content): void
    {
        $content->versions()->create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'metadata' => $content->metadata,
            'user_id' => $content->user_id,
            'status' => $content->status,
            'version' => $content->version,
            'created_at' => now()
        ]);
    }

    protected function processMetadata(array $metadata): array
    {
        return array_merge(
            $metadata,
            [
                'last_modified' => now()->toIso8601String(),
                'version_hash' => $this->generateVersionHash($metadata)
            ]
        );
    }

    protected function generateVersionHash(array $data): string
    {
        return hash('sha256', json_encode($data) . microtime(true));
    }

    protected function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}
