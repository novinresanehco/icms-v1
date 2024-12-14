namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Content\Models\Content;
use App\Core\Content\Events\ContentEvent;
use Illuminate\Support\Facades\{DB, Cache, Event};

class ContentManagementService implements ContentManagementInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ContentValidator $validator;
    private CacheManager $cache;
    private VersionManager $versions;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ContentValidator $validator,
        CacheManager $cache,
        VersionManager $versions,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->versions = $versions;
        $this->logger = $logger;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data),
            $context,
            function() use ($data) {
                $validated = $this->validator->validateCreation($data);
                
                return DB::transaction(function() use ($validated) {
                    $content = $this->repository->create($validated);
                    
                    $this->versions->createInitialVersion($content);
                    $this->cache->invalidateContent($content->id);
                    $this->logger->logContentCreation($content);
                    
                    Event::dispatch(new ContentEvent('created', $content));
                    
                    return $content;
                });
            }
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data),
            $context,
            function() use ($id, $data) {
                $content = $this->repository->findOrFail($id);
                $validated = $this->validator->validateUpdate($data);
                
                return DB::transaction(function() use ($content, $validated) {
                    $this->versions->createVersion($content);
                    
                    $updated = $this->repository->update($content->id, $validated);
                    $this->cache->invalidateContent($content->id);
                    $this->logger->logContentUpdate($content, $validated);
                    
                    Event::dispatch(new ContentEvent('updated', $updated));
                    
                    return $updated;
                });
            }
        );
    }

    public function publish(int $id, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id),
            $context,
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                return DB::transaction(function() use ($content) {
                    $this->validator->validatePublishing($content);
                    
                    $published = $this->repository->publish($content->id);
                    $this->cache->invalidateContent($content->id);
                    $this->logger->logContentPublish($published);
                    
                    Event::dispatch(new ContentEvent('published', $published));
                    
                    return $published;
                });
            }
        );
    }

    public function delete(int $id, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new DeleteContentOperation($id),
            $context,
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                DB::transaction(function() use ($content) {
                    $this->versions->archiveVersions($content);
                    $this->repository->delete($content->id);
                    $this->cache->invalidateContent($content->id);
                    $this->logger->logContentDeletion($content);
                    
                    Event::dispatch(new ContentEvent('deleted', $content));
                });
            }
        );
    }

    public function revertToVersion(int $id, int $versionId, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new RevertContentOperation($id, $versionId),
            $context,
            function() use ($id, $versionId) {
                $content = $this->repository->findOrFail($id);
                $version = $this->versions->findVersion($content, $versionId);
                
                return DB::transaction(function() use ($content, $version) {
                    $this->versions->createVersion($content);
                    
                    $reverted = $this->repository->revertToVersion($content->id, $version);
                    $this->cache->invalidateContent($content->id);
                    $this->logger->logContentReversion($content, $version);
                    
                    Event::dispatch(new ContentEvent('reverted', $reverted));
                    
                    return $reverted;
                });
            }
        );
    }

    public function getVersion(int $id, int $versionId, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new GetContentVersionOperation($id, $versionId),
            $context,
            function() use ($id, $versionId) {
                $content = $this->repository->findOrFail($id);
                return $this->versions->findVersion($content, $versionId);
            }
        );
    }

    public function getAllVersions(int $id, SecurityContext $context): array
    {
        return $this->security->executeCriticalOperation(
            new GetContentVersionsOperation($id),
            $context,
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                return $this->versions->getAllVersions($content);
            }
        );
    }

    private function validateAndCache(Content $content): Content
    {
        $this->validator->validateContent($content);
        $this->cache->storeContent($content);
        return $content;
    }

    private function clearContentCache(int $id): void
    {
        $this->cache->invalidateContent($id);
        $this->cache->invalidateContentLists();
    }
}
