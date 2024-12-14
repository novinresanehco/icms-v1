<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface
{
    private SecurityManager $security;
    private RuleEngine $rules;
    private AuditLogger $logger;

    public function validateOperation(Operation $operation): Result
    {
        return DB::transaction(function() use ($operation) {
            $chain = $this->buildValidationChain($operation);
            
            $this->security->validateAccess($operation);
            
            $result = $chain->execute($operation);
            
            $this->validateResult($result);
            
            $this->logger->logValidation($operation, $result);
            
            return $result;
        });
    }

    private function buildValidationChain(Operation $operation): ValidationChain
    {
        return new ValidationChain([
            new SecurityValidator([
                'authentication' => true,
                'authorization' => true,
                'rateLimit' => true
            ]),
            new InputValidator([
                'sanitize' => true,
                'typeCheck' => true,
                'sizeLimit' => true
            ]),
            new BusinessValidator([
                'rules' => $this->rules->forOperation($operation),
                'constraints' => $this->getConstraints($operation)
            ]),
            new IntegrityValidator([
                'checksum' => true,
                'signature' => true,
                'encryption' => true
            ])
        ]);
    }

    private function validateResult(Result $result): void
    {
        $resultChain = new ValidationChain([
            new ResultIntegrityValidator(),
            new SecurityComplianceValidator(),
            new BusinessRuleValidator()
        ]);

        $resultChain->execute($result);
    }

    private function getConstraints(Operation $operation): array
    {
        return match($operation->getType()) {
            'create' => $this->rules->getCreateConstraints(),
            'update' => $this->rules->getUpdateConstraints(),
            'delete' => $this->rules->getDeleteConstraints(),
            default => $this->rules->getDefaultConstraints()
        };
    }
}