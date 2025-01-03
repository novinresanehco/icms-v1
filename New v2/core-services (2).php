<?php

namespace App\Core\Services;

use App\Core\Security\SecurityManager;
use App\Core\Audit\AuditLogger;

abstract class BaseService
{
    protected SecurityManager $security;
    protected AuditLogger $logger;
    protected array $validators = [];

    protected function executeSecure(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateAccess($context);
            $result = $operation();
            DB::commit();
            $this->logger->logOperation(static::class, $context);
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailure($e, $context);
            throw $e;
        }
    }

    protected function validate(array $data, string $validator): array
    {
        return $this->validators[$validator]->validate($data);
    }
}

class ContentService extends BaseService
{
    private ContentRepository $repository;

    public function store(array $data, SecurityContext $context): Content
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validate($data, 'content');
            return $this->repository->store($validated);
        }, $context);
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->executeSecure(function() use ($id, $data) {
            $validated = $this->validate($data, 'content');
            return $this->repository->update($id, $validated);
        }, $context);
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->executeSecure(function() use ($id) {
            return $this->repository->delete($id);
        }, $context);
    }
}

class MediaService extends BaseService
{
    private MediaRepository $repository;
    private StorageManager $storage;

    public function store(UploadedFile $file, SecurityContext $context): Media
    {
        return $this->executeSecure(function() use ($file) {
            $path = $this->storage->store($file);
            return $this->repository->store(['path' => $path]);
        }, $context);
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->executeSecure(function() use ($id) {
            $media = $this->repository->find($id);
            $this->storage->delete($media->path);
            return $this->repository->delete($id);
        }, $context);
    }
}

class UserService extends BaseService 
{
    private UserRepository $repository;

    public function create(array $data, SecurityContext $context): User
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validate($data, 'user');
            $validated['password'] = bcrypt($validated['password']);
            return $this->repository->store($validated);
        }, $context);
    }

    public function update(int $id, array $data, SecurityContext $context): User
    {
        return $this->executeSecure(function() use ($id, $data) {
            $validated = $this->validate($data, 'user');
            if (isset($validated['password'])) {
                $validated['password'] = bcrypt($validated['password']);
            }
            return $this->repository->update($id, $validated);
        }, $context);
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->executeSecure(function() use ($id) {
            return $this->repository->delete($id);
        }, $context);
    }
}

class AuthService extends BaseService
{
    private UserRepository $users;
    private TokenManager $tokens;

    public function authenticate(array $credentials): AuthResult
    {
        $validated = $this->validate($credentials, 'auth');
        
        $user = $this->users->findByEmail($validated['email']);
        
        if (!$user || !$this->verifyPassword($validated['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }
        
        $token = $this->tokens->generate($user);
        return new AuthResult($user, $token);
    }

    public function validateToken(string $token): User
    {
        return $this->tokens->validate($token);
    }

    private function verifyPassword(string $input, string $hash): bool
    {
        return password_verify($input, $hash);
    }
}
