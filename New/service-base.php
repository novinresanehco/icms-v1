<?php

namespace App\Core\Service;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityContext;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;

abstract class BaseService
{
    protected SecurityManager $security;
    protected ValidationService $validator;

    protected function executeSecure(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            $this->validateContext($context);
            $result = $operation();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function validateContext(SecurityContext $context): void
    {
        if (!$this->security->validateContext($context)) {
            throw new SecurityException('Invalid security context');
        }
    }

    protected function validateData(array $data, array $rules): array
    {
        return $this->validator->validate($data, $rules);
    }

    protected function logAudit(string $action, array $data, SecurityContext $context): void
    {
        $this->security->logAudit($action, $data, $context);
    }
}
