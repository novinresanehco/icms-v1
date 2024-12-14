namespace App\Core\CMS;

class CMSService
{
    private AuthenticationSystem $auth;
    private DatabaseManager $db;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditService $audit;
    
    private const CACHE_TTL = 3600;
    private const MAX_BATCH_SIZE = 100;

    public function createContent(CreateContentDTO $data, User $user): Content
    {
        return $this->executeSecureOperation(function() use ($data, $user) {
            // Validate input
            $validatedData = $this->validator->validateContent($data);
            
            // Check permissions
            if (!$user->can('create_content')) {
                throw new UnauthorizedException('Content creation not allowed');
            }

            DB::beginTransaction();
            try {
                // Create content record
                $content = new Content($validatedData);
                $content->user_id = $user->id;
                $content->save();

                // Process related data
                $this->processMediaAttachments($content, $data->media);
                $this->processCategories($content, $data->categories);
                
                DB::commit();

                // Clear relevant caches
                $this->cache->tags(['content'])->flush();
                
                // Audit trail
                $this->audit->logContentCreation($content, $user);

                return $content;
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function updateContent(int $id, UpdateContentDTO $data, User $user): Content
    {
        return $this->executeSecureOperation(function() use ($id, $data, $user) {
            $content = Content::findOrFail($id);
            
            // Check permissions
            if (!$this->canModifyContent($user, $content)) {
                throw new UnauthorizedException('Content modification not allowed');
            }

            // Validate input
            $validatedData = $this->validator->validateContent($data);

            DB::beginTransaction();
            try {
                // Update content
                $content->fill($validatedData);
                $content->save();

                // Update relationships
                $this->updateMediaAttachments($content, $data->media);
                $this->updateCategories($content, $data->categories);
                
                DB::commit();
                
                // Clear caches
                $this->cache->tags(['content', "content.{$id}"])->flush();
                
                // Audit trail
                $this->audit->logContentUpdate($content, $user);

                return $content->fresh();
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function getContent(int $id, ?User $user = null): Content
    {
        return $this->cache->tags("content.{$id}")->remember(
            "content.{$id}",
            self::CACHE_TTL,
            function() use ($id, $user) {
                $content = Content::with(['media', 'categories'])->findOrFail($id);
                
                if (!$this->canViewContent($user, $content)) {
                    throw new UnauthorizedException('Content access not allowed');
                }

                // Audit trail
                $this->audit->logContentAccess($content, $user);

                return $content;
            }
        );
    }

    protected function executeSecureOperation(callable $operation)
    {
        try {
            return $operation();
        } catch (Exception $e) {
            $this->handleOperationError($e);
            throw $e;
        }
    }

    protected function canModifyContent(User $user, Content $content): bool
    {
        return 
            $user->isAdmin() ||
            $content->user_id === $user->id ||
            $user->can('modify_all_content');
    }

    protected function canViewContent(?User $user, Content $content): bool
    {
        if ($content->isPublic()) {
            return true;
        }

        return $user && (
            $user->isAdmin() ||
            $content->user_id === $user->id ||
            $user->can('view_all_content')
        );
    }

    protected function processMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            // Validate media item
            $this->validator->validateMedia($item);
            
            // Process and store media securely
            $processedMedia = $this->processMediaItem($item);
            
            // Attach to content
            $content->media()->create($processedMedia);
        }
    }

    protected function processCategories(Content $content, array $categories): void
    {
        // Validate categories
        $validCategories = $this->validator->validateCategories($categories);
        
        // Sync relationships
        $content->categories()->sync($validCategories);
    }

    protected function handleOperationError(Exception $e): void
    {
        // Log error
        $this->audit->logError($e);

        // Clean up any resources if needed
        $this->cleanupResources($e);
    }
}
