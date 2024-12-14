namespace App\Core\Services;

class ContentManagementService implements ContentServiceInterface
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;
    private EventDispatcher $events;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $auditLogger,
        MetricsCollector $metrics,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
        $this->events = $events;
    }

    public function createContent(array $data): ContentResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Validate access
            $this->security->validateAccess('content.create');

            // Validate input
            $validated = $this->validator->validate($data, $this->getValidationRules());

            // Process content
            $processed = $this->processContentData($validated);

            // Store content
            $content = $this->repository->create($processed);

            // Handle media attachments
            if (!empty($processed['media'])) {
                $this->handleMediaAttachments($content, $processed['media']);
            }

            // Clear relevant caches
            $this->cache->tags(['content'])->flush();

            // Log operation
            $this->auditLogger->logContentOperation('create', $content->id, $validated);

            // Dispatch events
            $this->events->dispatch(new ContentCreated($content));

            DB::commit();

            // Record metrics
            $this->metrics->recordOperation(
                'content.create',
                microtime(true) - $startTime,
                ['content_type' => $content->type]
            );

            return new ContentResult(true, $content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationError('create', $e, $data);
            throw $e;
        }
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Validate access
            $this->security->validateAccess('content.update', $id);

            // Validate input
            $validated = $this->validator->validate($data, $this->getValidationRules());

            // Verify content exists
            $content = $this->repository->findOrFail($id);

            // Create version snapshot
            $this->createVersionSnapshot($content);

            // Process content
            $processed = $this->processContentData($validated);

            // Update content
            $updated = $this->repository->update($id, $processed);

            // Update media attachments
            if (isset($processed['media'])) {
                $this->handleMediaAttachments($updated, $processed['media']);
            }

            // Clear relevant caches
            $this->cache->tags(['content', "content:$id"])->flush();

            // Log operation
            $this->auditLogger->logContentOperation('update', $id, $validated);

            // Dispatch events
            $this->events->dispatch(new ContentUpdated($updated));

            DB::commit();

            // Record metrics
            $this->metrics->recordOperation(
                'content.update',
                microtime(true) - $startTime,
                ['content_type' => $updated->type]
            );

            return new ContentResult(true, $updated);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationError('update', $e, $data);
            throw $e;
        }
    }

    public function publishContent(int $id): ContentResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Validate access
            $this->security->validateAccess('content.publish', $id);

            // Get content
            $content = $this->repository->findOrFail($id);

            // Verify publishable
            $this->verifyPublishable($content);

            // Create publication snapshot
            $this->createPublicationSnapshot($content);

            // Update status
            $published = $this->repository->markAsPublished($id);

            // Clear caches
            $this->cache->tags(['content', "content:$id"])->flush();

            // Log operation
            $this->auditLogger->logContentOperation('publish', $id);

            // Dispatch events
            $this->events->dispatch(new ContentPublished($published));

            DB::commit();

            // Record metrics
            $this->metrics->recordOperation(
                'content.publish',
                microtime(true) - $startTime,
                ['content_type' => $published->type]
            );

            return new ContentResult(true, $published);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationError('publish', $e, ['id' => $id]);
            throw $e;
        }
    }

    protected function processContentData(array $data): array
    {
        // Handle SEO metadata
        if (isset($data['seo'])) {
            $data['seo'] = $this->processSEOData($data['seo']);
        }

        // Process content body
        if (isset($data['body'])) {
            $data['body'] = $this->sanitizeContent($data['body']);
        }

        // Handle categories
        if (isset($data['categories'])) {
            $data['categories'] = $this->validateCategories($data['categories']);
        }

        return $data;
    }

    protected function handleMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            $this->validateMediaItem($item);
            $this->repository->attachMedia($content->id, $item['id']);
        }
    }

    protected function createVersionSnapshot(Content $content): void
    {
        $this->repository->createSnapshot($content->id, [
            'type' => 'version',
            'data' => $content->toArray(),
            'metadata' => [
                'timestamp' => now(),
                'user_id' => auth()->id()
            ]
        ]);
    }

    protected function verifyPublishable(Content $content): void
    {
        if (!$content->isPublishable()) {
            throw new ContentNotPublishableException(
                'Content does not meet publication requirements'
            );
        }
    }

    protected function handleOperationError(string $operation, \Exception $e, array $context): void
    {
        $this->auditLogger->logError($operation, [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementError($operation);
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'type' => 'required|string',
            'categories' => 'array',
            'categories.*' => 'exists:categories,id',
            'media' => 'array',
            'media.*.id' => 'exists:media,id',
            'seo' => 'array'
        ];
    }
}
