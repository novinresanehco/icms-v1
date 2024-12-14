<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface 
{
    private ContentRepository $repository;
    private ContentSecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function createContent(array $data): ContentResult
    {
        return DB::transaction(function() use ($data) {
            // Validate and secure content
            $validated = $this->validator->validateContent($data);
            $secured = $this->security->secureContent($validated);
            
            // Store with monitoring
            $content = $this->repository->store($secured);
            $this->logger->logContentCreation($content);
            
            // Cache and return
            $this->cache->put($content->getCacheKey(), $content);
            return new ContentResult($content);
        });
    }

    public function retrieveContent(string $id): ContentResult
    {
        return $this->cache->remember(
            "content.$id",
            function() use ($id) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentNotFoundException($id);
                }

                if (!$this->security->validateContentAccess($content, auth()->user())) {
                    throw new ContentAccessDeniedException();
                }

                return new ContentResult($content);
            }
        );
    }

    public function updateContent(string $id, array $data): ContentResult
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->repository->find($id);
            
            if (!$content) {
                throw new ContentNotFoundException($id);
            }

            if (!$this->security->validateContentAccess($content, auth()->user())) {
                throw new ContentAccessDeniedException();
            }

            $validated = $this->validator->validateContent($data);
            $updated = $content->update($validated);
            $secured = $this->security->secureContent($updated);
            
            $this->repository->update($id, $secured);
            $this->cache->forget("content.$id");
            $this->logger->logContentUpdate($content);
            
            return new ContentResult($updated);
        });
    }

    public function deleteContent(string $id): void
    {
        DB::transaction(function() use ($id) {
            $content = $this->repository->find($id);
            
            if (!$content) {
                throw new ContentNotFoundException($id);
            }

            if (!$this->security->validateContentAccess($content, auth()->user())) {
                throw new ContentAccessDeniedException();
            }

            $this->repository->delete($id);
            $this->cache->forget("content.$id");
            $this->logger->logContentDeletion($content);
        });
    }
}

class ContentValidator implements ValidationInterface
{
    private array $rules;

    public function validateContent(array $data): ValidatedContent
    {
        $errors = [];

        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                $errors[$field] = "Validation failed for $field";
            }
        }

        if (!empty($errors)) {
            throw new ContentValidationException($errors);
        }

        return new ValidatedContent($data);
    }

    private function validateField($value, ContentRule $rule): bool
    {
        return $rule->validate($value);
    }
}

class ContentRepository implements RepositoryInterface
{
    private Database $db;
    private QueryBuilder $query;
    private ContentCache $cache;

    public function find(string $id): ?Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->query->table('contents')->find($id);
        });
    }

    public function store(ValidatedContent $content): Content
    {
        $id = $this->db->table('contents')->insertGetId($content->toArray());
        return $this->find($id);
    }

    public function update(string $id, ValidatedContent $content): Content
    {
        $this->db->table('contents')->where('id', $id)->update($content->toArray());
        $this->cache->forget("content.$id");
        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->db->table('contents')->where('id', $id)->delete();
        $this->cache->forget("content.$id");
    }
}
