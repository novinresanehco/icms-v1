<?php

namespace App\Core\Operations;

use App\Core\Security\SecurityContext;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationManagerInterface;

abstract class CriticalOperation
{
    protected SecurityManagerInterface $security;
    protected ValidationManagerInterface $validator;
    
    public function __construct(
        SecurityManagerInterface $security,
        ValidationManagerInterface $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function execute(array $data, SecurityContext $context): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performOperation($data, $context),
            $context
        );
    }

    abstract protected function performOperation(array $data, SecurityContext $context): mixed;
    
    abstract protected function getValidationRules(): array;

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->getValidationRules());
    }

    protected function verifyAccess(SecurityContext $context): void
    {
        if (!$this->security->verifyAccess($context)) {
            throw new SecurityException('Access denied');
        }
    }

    protected function logOperation(string $action, array $data, SecurityContext $context): void
    {
        $this->security->logAction($action, $data, $context);
    }
}
