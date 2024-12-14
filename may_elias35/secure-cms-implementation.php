<?php

namespace App\Core\CMS;

class ContentManager
{
    private SecurityService $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;
    private Repository $repository;

    public function __construct(
        SecurityService $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $logger,
        Repository $repository
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->repository = $repository;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        return $this->executeSecureOperation('create', function() use ($data, $context) {
            $validatedData = $this->validator->validate($data, ContentRules::CREATE);
            $secureData = $this->security->encryptSensitiveData($validatedData);
            
            $content = $this->repository->create($secureData);
            $this->cache->invalidate(['content', $content->getId()]);
            
            return new ContentResult($content);
        }, $context);
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult
    {
        return $this->executeSecureOperation('update', function() use ($id, $data, $context) {
            $this->validateAccess($id, 'update', $context);
            $validatedData = $this->validator->validate($data, ContentRules::UPDATE);
            
            $content = $this->repository->update($id, $validatedData);
            $this->cache->invalidate(['content', $id]);
            
            return new ContentResult($content);
        }, $context);
    }

    public function getContent(int $id, SecurityContext $context): ContentResult
    {
        return $this->executeSecureOperation('read', function() use ($id, $context) {
            $this->validateAccess($id, 'read', $context);
            
            return $this->cache->remember(['content', $id], function() use ($id) {
                $content = $this->repository->find($id);
                return new ContentResult($content);
            });
        }, $context);
    }

    private function executeSecureOperation(string $type, callable $operation, SecurityContext $context): ContentResult
    {
        $this->logger->startOperation($type, $context);
        DB::beginTransaction();

        try {
            $this->security->validateContext($context);
            $result = $operation();
            
            DB::commit();
            $this->logger->completeOperation($type, $context);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->failOperation($type, $context, $e);
            $this->security->handleFailure($e);
            throw $e;
        }
    }

    private function validateAccess(int $contentId, string $operation, SecurityContext $context): void
    {
        if (!$this->security->hasPermission($context, $operation, $contentId)) {
            throw new AccessDeniedException("Access denied for $operation on content $contentId");
        }
    }
}

class ContentValidator 
{
    private array $rules;
    private array $sanitizers;

    public function validate(array $data, string $ruleSet): array
    {
        $rules = $this->rules[$ruleSet] ?? throw new ValidationException("Invalid rule set: $ruleSet");

        $validated = [];
        foreach ($rules as $field => $rule) {
            if (!isset($data[$field]) && $rule['required']) {
                throw new ValidationException("Required field missing: $field");
            }

            $value = $data[$field] ?? null;
            $validated[$field] = $this->validateField($value, $rule);
        }

        return $validated;
    }

    private function validateField($value, array $rule): mixed
    {
        if ($rule['sanitize']) {
            $value = $this->sanitizers[$rule['sanitize']]($value);
        }

        if (!$this->checkType($value, $rule['type'])) {
            throw new ValidationException("Invalid type for field");
        }

        foreach ($rule['constraints'] as $constraint) {
            if (!$this->checkConstraint($value, $constraint)) {
                throw new ValidationException("Constraint failed: $constraint");
            }
        }

        return $value;
    }

    private function checkType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'array' => is_array($value),
            'bool' => is_bool($value),
            default => false
        };
    }

    private function checkConstraint($value, array $constraint): bool
    {
        return match($constraint['type']) {
            'length' => strlen($value) <= $constraint['max'],
            'range' => $value >= $constraint['min'] && $value <= $constraint['max'],
            'pattern' => preg_match($constraint['pattern'], $value),
            'enum' => in_array($value, $constraint['values']),
            default => false
        };
    }
}

class ContentRepository
{
    private DB $database;
    private QueryBuilder $query;
    private CacheManager $cache;

    public function find(int $id): ?Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->query
                ->select('contents')
                ->where('id', $id)
                ->where('active', true)
                ->first();
        });
    }

    public function create(array $data): Content
    {
        $content = $this->database->transaction(function() use ($data) {
            $content = $this->query
                ->insert('contents', $data)
                ->returning('*')
                ->first();

            $this->createRevision($content);
            return $content;
        });

        $this->cache->invalidate("content.{$content->id}");
        return $content;
    }

    private function createRevision(Content $content): void
    {
        $this->query->insert('content_revisions', [
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_at' => now()
        ]);
    }
}

interface Content
{
    public function getId(): int;
    public function getData(): array;
    public function getMetadata(): array;
    public function toArray(): array;
}

interface ContentResult
{
    public function getContent(): Content;
    public function isSuccess(): bool;
    public function getErrors(): array;
}
