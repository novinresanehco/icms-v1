namespace App\Core\CMS;

use App\Core\Service\BaseService;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Repository\ContentRepository;
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManagementService extends BaseService
{
    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        CacheManager $cache,
        MonitoringService $monitor
    ) {
        parent::__construct($repository, $security, $cache, $monitor);
        $this->cachePrefix = 'content';
    }

    public function createContent(array $data): array
    {
        return $this->executeOperation(
            fn() => $this->repository->create($this->prepareContent($data)),
            ['action' => 'create_content', 'data' => $data]
        );
    }

    public function updateContent(int $id, array $data): array
    {
        return $this->executeOperation(
            fn() => $this->repository->update($id, $this->prepareContent($data)),
            ['action' => 'update_content', 'id' => $id, 'data' => $data]
        );
    }

    public function publishContent(int $id): array
    {
        return $this->executeOperation(
            function() use ($id) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                return $this->repository->update($id, [
                    'status' => 'published',
                    'published_at' => now()
                ]);
            },
            ['action' => 'publish_content', 'id' => $id]
        );
    }

    public function getContent(int $id): array
    {
        return $this->getCached(
            "content:$id",
            fn() => $this->executeOperation(
                fn() => $this->repository->find($id),
                ['action' => 'get_content', 'id' => $id]
            )
        );
    }

    public function listContent(array $filters = []): array
    {
        $cacheKey = 'list:' . md5(serialize($filters));
        
        return $this->getCached(
            $cacheKey,
            fn() => $this->executeOperation(
                fn() => $this->repository->list($filters),
                ['action' => 'list_content', 'filters' => $filters]
            )
        );
    }

    protected function prepareContent(array $data): array
    {
        $data = $this->sanitizeContent($data);
        $this->validateContent($data);
        
        return array_merge($data, [
            'version' => $this->generateVersion(),
            'metadata' => $this->generateMetadata($data)
        ]);
    }

    protected function sanitizeContent(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return strip_tags($value, $this->getAllowedTags());
            }
            return $value;
        }, $data);
    }

    protected function validateContent(array $data): void
    {
        $validator = validator($data, $this->getValidationRules());
        
        if ($validator->fails()) {
            throw new ValidationException(
                'Content validation failed: ' . $validator->errors()->first()
            );
        }
    }

    protected function generateVersion(): string
    {
        return hash('sha256', uniqid('content', true));
    }

    protected function generateMetadata(array $data): array
    {
        return [
            'created_at' => now(),
            'created_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
    }

    protected function getAllowedTags(): array
    {
        return [
            'p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 
            'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote'
        ];
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'in:draft,published,archived',
            'type' => 'required|string|in:page,post,article',
            'category_id' => 'exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'string|max:50',
            'meta_description' => 'string|max:160',
            'meta_keywords' => 'string|max:255'
        ];
    }
}
