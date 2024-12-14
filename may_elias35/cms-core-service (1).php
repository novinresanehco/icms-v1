namespace App\Core\Services;

use App\Core\Security\CoreSecurityManager;
use App\Core\Repositories\ContentRepository;
use App\Core\Events\ContentEvent;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ContentManagementService
{
    private ContentRepository $repository;
    private CoreSecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        ContentRepository $repository,
        CoreSecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function createContent(array $data, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($data, $context) {
                $validated = $this->validator->validate($data);
                
                $content = $this->repository->create([
                    'data' => $validated,
                    'user_id' => $context['user_id'],
                    'status' => 'draft',
                    'created_at' => now(),
                    'version' => 1
                ]);

                Event::dispatch(new ContentEvent('created', $content));
                
                $this->cache->put(
                    $this->getCacheKey($content['id']),
                    $content,
                    3600
                );

                return $content;
            },
            $context
        );
    }

    public function updateContent(int $id, array $data, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data, $context) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                $validated = $this->validator->validate($data);
                
                $updated = $this->repository->update($id, [
                    'data' => $validated,
                    'user_id' => $context['user_id'],
                    'updated_at' => now(),
                    'version' => $content['version'] + 1
                ]);

                Event::dispatch(new ContentEvent('updated', $updated));
                
                $this->cache->forget($this->getCacheKey($id));
                $this->cache->put(
                    $this->getCacheKey($id),
                    $updated,
                    3600
                );

                return $updated;
            },
            $context
        );
    }

    public function publishContent(int $id, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                if (!$this->validator->validatePublishState($content)) {
                    throw new ContentException('Content not ready for publishing');
                }

                $published = $this->repository->update($id, [
                    'status' => 'published',
                    'published_at' => now(),
                    'publisher_id' => $context['user_id']
                ]);

                Event::dispatch(new ContentEvent('published', $published));
                
                $this->cache->forget($this->getCacheKey($id));
                $this->cache->put(
                    $this->getCacheKey($id),
                    $published,
                    3600
                );

                return $published;
            },
            $context
        );
    }

    public function getContent(int $id, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                $cacheKey = $this->getCacheKey($id);
                
                if ($cached = $this->cache->get($cacheKey)) {
                    return $cached;
                }

                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                $this->cache->put($cacheKey, $content, 3600);
                
                return $content;
            },
            $context
        );
    }

    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                $this->repository->softDelete($id, [
                    'deleted_by' => $context['user_id'],
                    'deleted_at' => now()
                ]);

                Event::dispatch(new ContentEvent('deleted', $content));
                
                $this->cache->forget($this->getCacheKey($id));
                
                return true;
            },
            $context
        );
    }

    public function versionContent(int $id, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                return $this->repository->getVersions($id);
            },
            $context
        );
    }

    private function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}
