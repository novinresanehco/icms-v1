<?php

namespace App\Core\Services;

class ContentService implements ContentServiceInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function create(ContentData $data, SecurityContext $context): Content
    {
        try {
            // Validate content
            $this->validator->validateContent($data);
            
            // Create content
            $content = $this->repository->create($data);
            
            // Clear cache
            $this->cache->forget($this->getCacheKey($content->id));
            
            // Log creation
            $this->logger->logContentCreation($content, $context);
            
            return $content;

        } catch (\Exception $e) {
            $this->handleServiceFailure('create', $e);
            throw $e;
        }
    }

    public function update(int $id, ContentData $data, SecurityContext $context): Content
    {
        try {
            // Get existing content
            $content = $this->repository->find($id);
            
            // Validate update
            $this->validator->validateUpdate($content, $data);
            
            // Update content
            $updated = $this->repository->update($id, $data);
            
            // Clear cache
            $this->cache->forget($this->getCacheKey($id));
            
            // Log update
            $this->logger->logContentUpdate($updated, $context);
            
            return $updated;

        } catch (\Exception $e) {
            $this->handleServiceFailure('update', $e);
            throw $e;
        }
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        try {
            // Get existing content
            $content = $this->repository->find($id);
            
            // Validate deletion
            $this->validator->validateDeletion($content);
            
            // Delete content
            $this->repository->delete($id);
            
            // Clear cache
            $this->cache->forget($this->getCacheKey($id));
            
            // Log deletion
            $this->logger->logContentDeletion($content, $context);
            
            return true;

        } catch (\Exception $e) {
            $this->handleServiceFailure('delete', $e);
            throw $e;
        }
    }

    private function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}

class AuthenticationService implements AuthServiceInterface
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenGenerator $tokens;
    private AuditLogger $logger;

    public function authenticate(Credentials $credentials): AuthResult
    {
        try {
            // Validate credentials
            $this->validateCredentials($credentials);
            
            // Authenticate user
            $user = $this->users->findByCredentials($credentials);
            
            if (!$user) {
                throw new AuthenticationException('Invalid credentials');
            }
            
            // Generate token
            $token = $this->tokens->generate($user);
            
            // Log authentication
            $this->logger->logAuthentication($user);
            
            return new AuthResult($user, $token);

        } catch (\Exception $e) {
            $this->handleAuthFailure($e);
            throw $e;
        }
    }

    public function validateToken(string $token): bool
    {
        try {
            return $this->security->validateToken($token);
        } catch (\Exception $e) {
            $this->handleValidationFailure($e);
            return false;
        }
    }

    private function validateCredentials(Credentials $credentials): void
    {
        if (!$this->security->validateCredentials($credentials)) {
            throw new ValidationException('Invalid credentials format');
        }
    }
}