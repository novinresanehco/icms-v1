<?php

namespace App\Core\CMS;

class ContentSystem implements CMSInterface
{
    private SecurityEnforcer $security;
    private CriticalRepository $repository;
    private ValidationChain $validator;
    private CacheManager $cache;

    public function execute(Operation $operation): Result
    {
        return DB::transaction(function() use ($operation) {
            $this->validator->validateChain([
                new SecurityValidator($operation),
                new ContentValidator($operation),
                new BusinessRuleValidator($operation)
            ]);
            
            $result = match($operation->getType()) {
                'create' => $this->repository->create($operation->getData()),
                'update' => $this->repository->update(
                    $operation->getId(), 
                    $operation->getData()
                ),
                'delete' => $this->repository->delete($operation->getId()),
                'query' => $this->repository->executeQuery($operation)
            };

            $this->validator->validateChain([
                new ResultIntegrityValidator($result),
                new SecurityComplianceValidator($result)
            ]);

            $this->cache->manageTransaction($operation, $result);
            
            return $result;
        });
    }

    protected function validateInput(array $data): void
    {
        if (!$this->validator->validateInput($data)) {
            throw new ValidationException('Invalid input data');
        }

        if (!$this->security->validateAccess($data)) {
            throw new SecurityException('Access denied');
        }
    }

    protected function validateOutput($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid output data');
        }

        if (!$this->security->verifyIntegrity($result)) {
            throw new SecurityException('Data integrity violation');
        }
    }
}