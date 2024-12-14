namespace App\Core\CMS;

class ContentManagementSystem
{
    private SecurityManager $security;
    private DatabaseManager $db;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        DatabaseManager $db,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->db = $db;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function createContent(array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($data, $user) {
                // Validate input
                $validated = $this->validator->validate($data, 'content.create');
                
                // Process media if present
                if (isset($validated['media'])) {
                    $validated['media'] = $this->processMedia($validated['media']);
                }
                
                // Create content
                $content = $this->db->content()->create($validated);
                
                // Set permissions
                $this->setPermissions($content, $user);
                
                // Clear relevant caches
                $this->cache->tags(['content'])->flush();
                
                // Audit trail
                $this->audit->logContentCreation($content, $user);
                
                return $content;
            },
            new SecurityContext($user, 'content.create')
        );
    }

    public function updateContent(int $id, array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $data, $user) {
                $content = $this->db->content()->findOrFail($id);
                
                // Check ownership/permissions
                if (!$this->canEdit($content, $user)) {
                    throw new AuthorizationException();
                }
                
                // Validate updates
                $validated = $this->validator->validate($data, 'content.update');
                
                // Update content
                $content->update($validated);
                
                // Handle media updates
                if (isset($validated['media'])) {
                    $this->updateMedia($content, $validated['media']);
                }
                
                // Clear caches
                $this->cache->tags(['content', "content.{$id}"])->flush();
                
                // Audit trail
                $this->audit->logContentUpdate($content, $user);
                
                return $content->fresh();
            },
            new SecurityContext($user, 'content.update')
        );
    }

    public function getContent(int $id, ?User $user = null): Content
    {
        return $this->cache->tags("content.{$id}")->remember(
            "content.{$id}",
            3600,
            function() use ($id, $user) {
                $content = $this->db->content()->findOrFail($id);
                
                // Check view permissions
                if (!$this->canView($content, $user)) {
                    throw new AuthorizationException();
                }
                
                // Log access
                $this->audit->logContentAccess($content, $user);
                
                return $content;
            }
        );
    }

    public function deleteContent(int $id, User $user): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $user) {
                $content = $this->db->content()->findOrFail($id);
                
                // Check delete permissions
                if (!$this->canDelete($content, $user)) {
                    throw new AuthorizationException();
                }
                
                // Soft delete content
                $content->delete();
                
                // Clear caches
                $this->cache->tags(['content', "content.{$id}"])->flush();
                
                // Audit trail
                $this->audit->logContentDeletion($content, $user);
                
                return true;
            },
            new SecurityContext($user, 'content.delete')
        );
    }

    protected function canView(Content $content, ?User $user): bool
    {
        if ($content->isPublic()) {
            return true;
        }
        
        return $user && (
            $user->isAdmin() ||
            $content->user_id === $user->id ||
            $content->hasViewPermission($user)
        );
    }

    protected function canEdit(Content $content, User $user): bool
    {
        return $user->isAdmin() ||
               $content->user_id === $user->id ||
               $content->hasEditPermission($user);
    }

    protected function canDelete(Content $content, User $user): bool
    {
        return $user->isAdmin() ||
               $content->user_id === $user->id ||
               $content->hasDeletePermission($user);
    }

    protected function processMedia(array $media): array
    {
        // Process and store media files securely
        return array_map(function($item) {
            return $this->mediaProcessor->process($item);
        }, $media);
    }

    protected function updateMedia(Content $content, array $media): void
    {
        // Handle media updates/deletions
        $content->media()->delete();
        $processed = $this->processMedia($media);
        $content->media()->createMany($processed);
    }

    protected function setPermissions(Content $content, User $user): void
    {
        // Set initial permissions
        $content->permissions()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'granted_by' => $user->id
        ]);
    }
}
