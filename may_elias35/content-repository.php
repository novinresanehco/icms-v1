namespace App\Core\Content;

class ContentRepository implements ContentRepositoryInterface
{
    private DB $db;
    private CacheManager $cache;
    private ValidationService $validator;
    private SecurityManager $security;
    private AuditLogger $logger;
    private array $config;

    public function __construct(
        DB $db,
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $security,
        AuditLogger $logger,
        ConfigRepository $config
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
        $this->logger = $logger;
        $this->config = $config->get('content');
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            $this->config['cache_ttl'],
            fn() => $this->findById($id)
        );
    }

    public function findOrFail(int $id): Content
    {
        if (!$content = $this->find($id)) {
            throw new ContentNotFoundException($id);
        }
        return $content;
    }

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            // Create content record
            $content = $this->db->table('contents')->create([
                'title' => $data['title'],
                'slug' => $this->generateSlug($data['title']),
                'content' => $data['content'],
                'status' => ContentStatus::DRAFT,
                'type' => $data['type'],
                'user_id' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Handle metadata
            if (!empty($data['metadata'])) {
                $this->createMetadata($content->id, $data['metadata']);
            }

            // Handle categories
            if (!empty($data['categories'])) {
                $this->attachCategories($content->id, $data['categories']);
            }

            // Handle tags
            if (!empty($data['tags'])) {
                $this->attachTags($content->id, $data['tags']);
            }

            // Log creation
            $this->logger->logContentCreation($content);

            return $this->find($content->id);
        });
    }

    public function update(Content $content, array $data): Content
    {
        return DB::transaction(function() use ($content, $data) {
            // Update content record
            $this->db->table('contents')->where('id', $content->id)->update([
                'title' => $data['title'] ?? $content->title,
                'slug' => $data['title'] ? $this->generateSlug($data['title']) : $content->slug,
                'content' => $data['content'] ?? $content->content,
                'type' => $data['type'] ?? $content->type,
                'updated_at' => now()
            ]);

            // Update metadata
            if (isset($data['metadata'])) {
                $this->updateMetadata($content->id, $data['metadata']);
            }

            // Update categories
            if (isset($data['categories'])) {
                $this->syncCategories($content->id, $data['categories']);
            }

            // Update tags
            if (isset($data['tags'])) {
                $this->syncTags($content->id, $data['tags']);
            }

            // Clear cache
            $this->cache->forget($this->getCacheKey($content->id));

            // Log update
            $this->logger->logContentUpdate($content->id, $data);

            return $this->find($content->id);
        });
    }

    public function delete(Content $content): bool
    {
        return DB::transaction(function() use ($content) {
            // Delete related records
            $this->deleteMetadata($content->id);
            $this->detachCategories($content->id);
            $this->detachTags($content->id);

            // Delete content
            $result = $this->db->table('contents')->delete($content->id);

            // Clear cache
            $this->cache->forget($this->getCacheKey($content->id));

            // Log deletion
            $this->logger->logContentDeletion($content);

            return $result;
        });
    }

    public function publish(Content $content): Content
    {
        return DB::transaction(function() use ($content) {
            $this->db->table('contents')
                ->where('id', $content->id)
                ->update([
                    'status' => ContentStatus::PUBLISHED,
                    'published_at' => now()
                ]);

            $this->cache->forget($this->getCacheKey($content->id));
            
            $this->logger->logContentPublish($content);

            return $this->find($content->id);
        });
    }

    private function findById(int $id): ?Content
    {
        $record = $this->db->table('contents')->find($id);
        
        if (!$record) {
            return null;
        }

        return new Content(
            array_merge($record, [
                'metadata' => $this->getMetadata($id),
                'categories' => $this->getCategories($id),
                'tags' => $this->getTags($id)
            ])
        );
    }

    private function generateSlug(string $title): string
    {
        $slug = Str::slug($title);
        $count = $this->db->table('contents')
            ->where('slug', 'like', $slug . '%')
            ->count();

        return $count ? "{$slug}-{$count}" : $slug;
    }

    private function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}
