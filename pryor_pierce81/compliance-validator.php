<?php

namespace App\Core\Compliance;

class ComplianceValidator implements ComplianceInterface
{
    private RuleEngine $ruleEngine;
    private PolicyValidator $policyValidator;
    private SecurityValidator $securityValidator;
    private AuditTracker $auditTracker;
    private ComplianceLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        RuleEngine $ruleEngine,
        PolicyValidator $policyValidator,
        SecurityValidator $securityValidator,
        AuditTracker $auditTracker,
        ComplianceLogger $logger,
        AlertSystem $alerts
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->policyValidator = $policyValidator;
        $this->securityValidator = $securityValidator;
        $this->auditTracker = $auditTracker;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function validateCompliance(Operation $operation): ValidationResult
    {
        $validationId = $this->initializeValidation($operation);
        
        try {
            DB::beginTransaction();

            $ruleValidation = $this->ruleEngine->validateRules($operation);
            $policyValidation = $this->policyValidator->validatePolicies($operation);
            $securityValidation = $this->securityValidator->validateSecurity($operation);

            if (!$this->isCompliant($ruleValidation, $policyValidation, $securityValidation)) {
                throw new ComplianceException('Compliance validation failed');
            }

            $result = new ValidationResult([
                'rules' => $ruleValidation,
                'policies' => $policyValidation,
                'security' => $securityValidation
            ]);

            $this->auditTracker->trackValidation($result);
            
            DB::commit();
            $this->logger->logSuccess($operation, $result);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $operation, $validationId);
            throw new CriticalComplianceException($e->getMessage(), $e);
        }
    }

    private function isCompliant(
        RuleValidation $rules,
        PolicyValidation $policies,
        SecurityValidation $security
    ): bool {
        return $rules->isValid() && 
               $policies->isValid() && 
               $security->isValid();
    }

    private function handleValidationFailure(
        \Exception $e,
        Operation $operation,
        string $validationId
    ): void {
        $this->logger->logFailure($e, $operation, $validationId);
        
        $this->alerts->dispatch(
            new ComplianceAlert(
                'Compliance validation failed',
                [
                    'operation' => $operation,
                    'validation' => $validationId,
                    'exception' => $e
                ]
            )
        );

        $this->auditTracker->trackFailure($operation, $validationId, $e);
    }
}
