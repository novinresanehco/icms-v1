<?php

namespace App\Core;

class CoreCMSManager implements CMSManagerInterface
{
    private SecurityManager $security;
    private ContentManager $content;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        ContentManager $content,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function executeSecureOperation(Operation $operation): Result
    {
        return DB::transaction(function() use ($operation) {
            $context = $this->createSecurityContext($operation);
            
            $this->validatePreConditions($operation, $context);
            
            $result = $this->content->execute($operation, $context);
            
            $this->validateResult($result, $context);
            
            $this->cache->manageCache($operation, $result);
            
            return $result;
        });
    }

    protected function validatePreConditions(Operation $operation, Context $context): void
    {
        if (!$this->security->validateAccess($context)) {
            throw new SecurityException('Access denied');
        }

        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid operation');
        }
    }

    protected function validateResult(Result $result, Context $context): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }

        if (!$this->security->verifyResultIntegrity($result, $context)) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    private function createSecurityContext(Operation $operation): Context
    {
        return new Context([
            'operation' => $operation->getType(),
            'user' => Auth::user(),
            'timestamp' => now(),
            'requestId' => Str::uuid(),
            'source' => $operation->getSource()
        ]);
    }
}