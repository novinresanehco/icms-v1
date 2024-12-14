<?php

namespace App\Core\Policy;

class PolicyEnforcementService implements PolicyEnforcementInterface
{
    private PolicyRepository $policyRepository;
    private RuleEngine $ruleEngine;
    private ComplianceChecker $complianceChecker;
    private ViolationHandler $violationHandler;
    private PolicyLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        PolicyRepository $policyRepository,
        RuleEngine $ruleEngine,
        ComplianceChecker $complianceChecker,
        ViolationHandler $violationHandler,
        PolicyLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->policyRepository = $policyRepository;
        $this->ruleEngine = $ruleEngine;
        $this->complianceChecker = $complianceChecker;
        $this->violationHandler = $violationHandler;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function enforcePolicy(PolicyContext $context): EnforcementResult
    {
        $enforcementId = $this->initializeEnforcement($context);
        
        try {
            DB::beginTransaction();

            $policy = $this->loadPolicy($context);
            $this->validatePolicy($policy);
            
            $violations = $this->evaluatePolicy($policy, $context);
            
            if (!empty($violations)) {
                $this->handleViolations($violations, $context);
            }

            $result = new EnforcementResult([
                'enforcementId' => $enforcementId,
                'status' => empty($violations) ? 
                    EnforcementStatus::COMPLIANT : 
                    EnforcementStatus::VIOLATED,
                'violations' => $violations,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (PolicyException $e) {
            DB::rollBack();
            $this->handleEnforcementFailure($e, $enforcementId);
            throw new CriticalPolicyException($e->getMessage(), $e);
        }
    }

    private function loadPolicy(PolicyContext $context): Policy
    {
        $policy = $this->policyRepository->getPolicy($context->getPolicyId());
        
        if (!$policy) {
            throw new PolicyNotFoundException('Required policy not found');
        }
        
        return $policy;
    }

    private function evaluatePolicy(Policy $policy, PolicyContext $context): array
    {
        $violations = $this->ruleEngine->evaluate($policy, $context);
        
        if ($violations && $policy->isCritical()) {
            $this->emergency->handleCriticalViolation($violations);
        }
        
        return $violations;
    }

    private function handleViolations(array $violations, PolicyContext $context): void
    {
        foreach ($violations as $violation) {
            $this->violationHandler->handle($violation);
            $this->logger->logViolation($violation, $context);
            
            if ($violation->isCritical()) {
                $this->emergency->handleCriticalViolation($violation);
            }
        }
    }

    private function handleEnforcementFailure(
        PolicyException $e,
        string $enforcementId
    ): void {
        $this->logger->logFailure($e, $enforcementId);
        
        if ($e->isCritical()) {
            $this->emergency->initiate(EmergencyLevel::CRITICAL);
        }
        
        $this->violationHandler->handleFailure($e);
    }
}
