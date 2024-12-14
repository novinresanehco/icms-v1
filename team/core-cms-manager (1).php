namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\CMS\Events\ContentEvent;
use App\Core\CMS\Services\{
    ValidationService,
    VersioningService,
    CacheService,
    MediaService
};

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private VersioningService $versioning;
    private CacheService $cache;
    private MediaService $media;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        VersioningService $versioning,
        CacheService $cache,
        MediaService $media,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->versioning = $versioning;
        $this->cache = $cache;
        $this->media = $media;
        $this->logger = $logger;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            $validated = $this->validator->validate($data, 'content.create');
            
            DB::beginTransaction();
            try {
                $content = new Content($validated);
                $content->save();

                if (isset($validated['media'])) {
                    $this->media->attachToContent($content, $validated['media']);
                }

                $this->versioning->createInitialVersion($content);
                $this->cache->invalidateContentCache($content->id);
                
                DB::commit();
                $this->logger->logContentCreation($content);
                
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['operation' => 'content.create', 'data' => $data]);
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            $content = $this->findOrFail($id);
            $validated = $this->validator->validate($data, 'content.update');
            
            DB::beginTransaction();
            try {
                $content->fill($validated);
                $content->save();

                if (isset($validated['media'])) {
                    $this->media->syncWithContent($content, $validated['media']);
                }

                $this->versioning->createNewVersion($content);
                $this->cache->invalidateContentCache($id);
                
                DB::commit();
                $this->logger->logContentUpdate($content);
                
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['operation' => 'content.update', 'id' => $id, 'data' => $data]);
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            $content = $this->findOrFail($id);
            
            if (!$this->validator->canPublish($content)) {
                throw new ValidationException('Content not ready for publishing');
            }
            
            DB::beginTransaction();
            try {
                $content->publish();
                $this->cache->invalidateContentCache($id);
                
                DB::commit();
                $this->logger->logContentPublish($content);
                
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['operation' => 'content.publish', 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            $content = $this->findOrFail($id);
            
            DB::beginTransaction();
            try {
                $this->media->detachFromContent($content);
                $this->versioning->archiveVersions($content);
                $content->delete();
                
                $this->cache->invalidateContentCache($id);
                
                DB::commit();
                $this->logger->logContentDeletion($content);
                
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['operation' => 'content.delete', 'id' => $id]);
    }

    public function restore(int $id, int $version): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $version) {
            $content = $this->findOrFail($id);
            
            DB::beginTransaction();
            try {
                $restored = $this->versioning->restoreVersion($content, $version);
                $this->cache->invalidateContentCache($id);
                
                DB::commit();
                $this->logger->logContentRestore($content, $version);
                
                return $restored;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['operation' => 'content.restore', 'id' => $id, 'version' => $version]);
    }

    public function findOrFail(int $id): Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            $content = Content::with(['media', 'currentVersion'])->findOrFail($id);
            if (!$content) {
                throw new ContentNotFoundException("Content with ID $id not found");
            }
            return $content;
        });
    }

    protected function validateOperation(string $operation, array $data): array
    {
        $validated = $this->validator->validate($data, "content.$operation");
        if (!$validated) {
            throw new ValidationException('Content validation failed');
        }
        return $validated;
    }
}
