<?php

namespace App\Core\Services;

abstract class CriticalService
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $audit;
    protected CacheManager $cache;
    protected MetricsCollector $metrics;

    protected function executeOperation($operation): Result
    {
        $operationId = $this->generateOperationId();
        $this->metrics->startOperation($operationId);

        try {
            DB::beginTransaction();
            
            $result = $this->security->executeWithProtection($operation);
            
            DB::commit();
            $this->audit->logSuccess($operationId, $operation);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, $operationId, $operation);
            throw $e;
        } finally {
            $this->metrics->endOperation($operationId);
        }
    }

    protected function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    protected function handleError(\Exception $e, string $operationId, $operation): void
    {
        $this->audit->logFailure($operationId, $operation, $e);
    }
}

class ContentService extends CriticalService
{
    private ContentRepository $repository;
    private CategoryRepository $categoryRepo;

    public function create(array $data): Content
    {
        $operation = new CreateContentOperation($data, $this->repository);
        return $this->executeOperation($operation);
    }

    public function update(string $id, array $data): Content
    {
        $operation = new UpdateContentOperation($id, $data, $this->repository);
        return $this->executeOperation($operation);
    }

    public function delete(string $id): void
    {
        $operation = new DeleteContentOperation($id, $this->repository);
        $this->executeOperation($operation);
    }

    public function publish(string $id): void
    {
        $operation = new PublishContentOperation($id, $this->repository);
        $this->executeOperation($operation);
    }

    public function validateContent(array $data): bool
    {
        return $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category_id' => 'required|exists:categories,id'
        ]);
    }
}

class CategoryService extends CriticalService
{
    private CategoryRepository $repository;

    public function create(array $data): Category
    {
        $operation = new CreateCategoryOperation($data, $this->repository);
        return $this->executeOperation($operation);
    }

    public function update(string $id, array $data): Category
    {
        $operation = new UpdateCategoryOperation($id, $data, $this->repository);
        return $this->executeOperation($operation);
    }

    public function delete(string $id): void
    {
        $operation = new DeleteCategoryOperation($id, $this->repository);
        $this->executeOperation($operation);
    }

    public function validateCategory(array $data): bool
    {
        return $this->validator->validate($data, [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:categories',
            'parent_id' => 'nullable|exists:categories,id'
        ]);
    }
}

class MediaService extends CriticalService
{
    private MediaRepository $repository;
    private StorageService $storage;

    public function upload(UploadedFile $file, array $data): Media
    {
        $operation = new UploadMediaOperation($file, $data, $this->repository, $this->storage);
        return $this->executeOperation($operation);
    }

    public function delete(string $id): void
    {
        $operation = new DeleteMediaOperation($id, $this->repository, $this->storage);
        $this->executeOperation($operation);
    }

    public function validateMedia(UploadedFile $file): bool
    {
        return $this->validator->validate([
            'file' => $file
        ], [
            'file' => 'required|file|mimes:jpeg,png,gif,pdf|max:10240'
        ]);
    }
}

class SearchService extends CriticalService
{
    private SearchEngine $engine;
    private ContentRepository $contentRepo;

    public function searchContent(string $query, array $filters = []): Collection
    {
        $operation = new SearchContentOperation($query, $filters, $this->engine);
        return $this->executeOperation($operation);
    }

    public function buildSearchIndex(): void
    {
        $operation = new BuildSearchIndexOperation($this->contentRepo, $this->engine);
        $this->executeOperation($operation);
    }
}

class CacheService extends CriticalService
{
    private CacheManager $cache;

    public function remember(string $key, callable $callback, int $ttl = 3600)
    {
        return $this->cache->remember($key, $ttl, function() use ($callback) {
            $operation = new CacheOperation($callback);
            return $this->executeOperation($operation);
        });
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }

    public function flush(): void
    {
        $this->cache->flush();
    }
}

class ValidationService extends CriticalService
{
    private array $rules = [];
    private array $messages = [];

    public function validate($data, array $rules = null): bool
    {
        $rules = $rules ?? $this->rules;
        
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException($this->messages[$field] ?? 'Validation failed');
            }
        }
        
        return true;
    }

    private function validateField($value, $rule): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            default => $this->validateCustomRule($value, $rule)
        };
    }

    private function validateCustomRule($value, $rule): bool
    {
        $validator = $this->getValidator($rule);
        return $validator->validate($value);
    }

    private function getValidator(string $rule): ValidatorInterface
    {
        if (!isset($this->validators[$rule])) {
            throw new ValidatorNotFoundException("Validator not found: {$rule}");
        }
        
        return $this->validators[$rule];
    }
}