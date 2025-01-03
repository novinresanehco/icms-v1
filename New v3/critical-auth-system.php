<?php

namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface
{
    private AuthManager $auth;
    private AccessControl $access;
    private AuditLogger $audit;
    private MetricsCollector $metrics;
    private EncryptionService $encryption;

    public function validateRequest(Request $request): ValidationResult
    {
        DB::beginTransaction();
        try {
            // Validate token
            $token = $this->tokens->validate($request->token());
            $user = $this->auth->validate($token);
            
            // Check permissions
            $this->access->checkPermissions($user, $request->getResource());
            
            // Log successful access
            $this->audit->logAccess($user, $request);
            
            DB::commit();
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logFailure($e);
            throw $e;
        }
    }

    public function encrypt(string $data): string
    {
        return $this->encryption->encrypt($data);
    }

    public function decrypt(string $data): string
    {
        return $this->encryption->decrypt($data);
    }
}

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private Repository $repository;
    private CacheManager $cache;
    
    public function store(array $data): Content
    {
        $validated = $this->validator->validate($data);
        $protected = $this->security->encrypt(json_encode($validated));

        return DB::transaction(function() use ($protected) {
            $content = $this->repository->store(['data' => $protected]);
            $this->cache->invalidate(['content', $content->id]);
            return $content;
        });
    }

    public function retrieve(int $id): Content
    {
        return $this->cache->remember(['content', $id], function() use ($id) {
            $content = $this->repository->find($id);
            $data = $this->security->decrypt($content->data);
            return new Content(json_decode($data, true));
        });
    }
}
