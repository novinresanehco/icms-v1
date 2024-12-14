<?php

namespace App\Core\Policy;

class PolicyVersionControl implements PolicyVersionInterface
{
    private PolicyRegistry $registry;
    private VersionValidator $validator;
    private PolicyMigrator $migrator;
    private PolicyLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        PolicyRegistry $registry,
        VersionValidator $validator,
        PolicyMigrator $migrator,
        PolicyLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->registry = $registry;
        $this->validator = $validator;
        $this->migrator = $migrator;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function validatePolicyVersion(PolicyContext $context): PolicyResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $currentPolicy = $this->registry->getCurrentPolicy();
            $targetPolicy = $context->getTargetPolicy();

            $this->validatePolicyTransition($currentPolicy, $targetPolicy);
            $migrationPlan = $this->createMigrationPlan($currentPolicy, $targetPolicy);
            $this->validateMigrationPlan($migrationPlan);

            $result = new PolicyResult([
                'validationId' => $validationId,
                'currentPolicy' => $currentPolicy,
                'targetPolicy' => $targetPolicy,
                'migrationPlan' => $migrationPlan,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (PolicyException $e) {
            DB::rollBack();
            $this->handlePolicyFailure($e, $validationId);
            throw new CriticalPolicyException($e->getMessage(), $e);
        }
    }

    private function validatePolicyTransition(Policy $current, Policy $target): void
    {
        $violations = $this->validator->validateTransition($current, $target);
        
        if (!empty($violations)) {
            $this->emergency->handlePolicyViolations($violations);
            throw new InvalidPolicyTransitionException(
                'Invalid policy transition',
                ['violations' => $violations]
            );
        }
    }

    private function createMigrationPlan(Policy $current, Policy $target): MigrationPlan
    {
        $plan = $this->migrator->createPlan($current, $target);
        
        if (!$plan->isViable()) {
            throw new InvalidMigrationPlanException('Unable to create viable migration plan');
        }
        
        return $plan;
    }

    private function validateMigrationPlan(MigrationPlan $plan): void
    {
        $issues = $this->validator->validatePlan($plan);
        
        if (!empty($issues)) {
            foreach ($issues as $issue) {
                if ($issue->isCritical()) {
                    $this->emergency->handleCriticalIssue($issue);
                }
            }
            throw new MigrationPlanException('Migration plan validation failed');
        }
    }
}
