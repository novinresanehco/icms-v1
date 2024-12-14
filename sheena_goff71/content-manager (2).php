namespace App\Core\Content;

class ContentManager implements ContentManagementInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private ContentRepository $repository;
    private VersionManager $versions;
    private AuditLogger $logger;

    public function create(array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->validator),
            SecurityContext::fromRequest()
        );
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->validator),
            SecurityContext::fromRequest()
        );
    }

    public function delete(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id),
            SecurityContext::fromRequest()
        );
    }

    public function publish(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->versions),
            SecurityContext::fromRequest()
        );
    }

    public function version(int $id): ContentVersion 
    {
        return $this->versions->createVersion($id);
    }

    public function restore(int $id, int $versionId): Content 
    {
        return $this->security->executeCriticalOperation(
            new RestoreContentOperation($id, $versionId, $this->versions),
            SecurityContext::fromRequest()
        );
    }

    public function find(int $id): ?Content 
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->repository->find($id);
        });
    }

    public function validate(array $data): array 
    {
        return $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
        ]);
    }

    protected function executeCreate(array $data): Content 
    {
        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            $this->versions->createInitialVersion($content);
            $this->logger->logContentCreation($content);
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function executeUpdate(Content $content, array $data): Content 
    {
        DB::beginTransaction();
        try {
            $updated = $this->repository->update($content->id, $data);
            $this->versions->createVersion($updated);
            $this->cache->forget("content.{$content->id}");
            $this->logger->logContentUpdate($updated);
            DB::commit();
            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function executeDelete(Content $content): bool 
    {
        DB::beginTransaction();
        try {
            $this->repository->delete($content->id);
            $this->versions->deleteAllVersions($content->id);
            $this->cache->forget("content.{$content->id}");
            $this->logger->logContentDeletion($content);
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function executePublish(Content $content): bool 
    {
        DB::beginTransaction();
        try {
            $content->status = 'published';
            $content->published_at = now();
            $this->repository->update($content->id, $content->toArray());
            $this->versions->createPublishedVersion($content);
            $this->cache->forget("content.{$content->id}");
            $this->logger->logContentPublication($content);
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
