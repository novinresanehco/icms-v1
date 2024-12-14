<?php

namespace App\Core\Compliance;

class ComplianceEnforcementService implements ComplianceEnforcerInterface
{
    private RuleEngine $ruleEngine;
    private StandardsValidator $standardsValidator;
    private RequirementChecker $requirementChecker;
    private ComplianceLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        RuleEngine $ruleEngine,
        StandardsValidator $standardsValidator,
        RequirementChecker $requirementChecker,
        ComplianceLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->standardsValidator = $standardsValidator;
        $this->requirementChecker = $requirementChecker;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function enforceCompliance(ComplianceContext $context): ComplianceResult
    {
        $enforcementId = $this->initializeEnforcement($context);
        
        try {
            DB::beginTransaction();

            $rules = $this->loadComplianceRules($context);
            $this->validateRules($rules);

            $standards = $this->evaluateStandards($context);
            $this->validateStandards($standards);

            $requirements = $this->checkRequirements($context);
            $this->validateRequirements($requirements);

            $result = new ComplianceResult([
                'enforcementId' => $enforcementId,
                'rules' => $rules,
                'standards' => $standards,
                'requirements' => $requirements,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (ComplianceException $e) {
            DB::rollBack();
            $this->handleComplianceFailure($e, $enforcementId);
            throw new CriticalComplianceException($e->getMessage(), $e);
        }
    }

    private function loadComplianceRules(ComplianceContext $context): array
    {
        $rules = $this->ruleEngine->loadRules($context);
        
        if (empty($rules)) {
            throw new RuleLoadException('Failed to load compliance rules');
        }
        
        return $rules;
    }

    private function validateStandards(array $standards): void
    {
        $violations = $this->standardsValidator->validate($standards);
        
        if (!empty($violations)) {
            $this->emergency->handleStandardsViolation($violations);
            throw new StandardsViolationException(
                'Standards validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function validateRequirements(array $requirements): void
    {
        foreach ($requirements as $requirement) {
            if (!$requirement->isMet()) {
                $this->handleUnmetRequirement($requirement);
            }
        }
    }

    private function handleUnmetRequirement(Requirement $requirement): void
    {
        $this->logger->logUnmetRequirement($requirement);
        
        if ($requirement->isCritical()) {
            $this->emergency->handleCriticalRequirementFailure($requirement);
            $this->alerts->dispatchCriticalAlert(
                new RequirementFailureAlert($requirement)
            );
            throw new CriticalRequirementException(
                'Critical requirement not met: ' . $requirement->getCode()
            );
        }
    }
}
