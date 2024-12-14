<?php

namespace App\Core\Audit;

class AuditPolicyManager
{
    private PolicyRepository $repository;
    private PolicyValidator $validator;
    private PolicyEvaluator $evaluator;
    private ComplianceChecker $complianceChecker;
    private EventDispatcher $dispatcher;
    private CacheManager $cache;

    public function __construct(
        PolicyRepository $repository,
        PolicyValidator $validator,
        PolicyEvaluator $evaluator,
        ComplianceChecker $complianceChecker,
        EventDispatcher $dispatcher,
        CacheManager $cache
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->evaluator = $evaluator;
        $this->complianceChecker = $complianceChecker;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
    }

    public function createPolicy(PolicyData $data): Policy
    {
        try {
            // Validate policy data
            $this->validator->validate($data);

            // Create policy
            $policy = new Policy([
                'id' => Str::uuid(),
                'name' => $data->getName(),
                'description' => $data->getDescription(),
                'rules' => $data->getRules(),
                'constraints' => $data->getConstraints(),
                'actions' => $data->getActions(),
                'metadata' => $data->getMetadata(),
                'status' => PolicyStatus::DRAFT,
                'version' => 1,
                'created_at' => now()
            ]);

            // Store policy
            $this->repository->store($policy);

            // Dispatch event
            $this->dispatcher->dispatch(new PolicyCreated($policy));

            return $policy;

        } catch (\Exception $e) {
            throw new PolicyCreationException(
                "Failed to create policy: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function evaluatePolicy(Policy $policy, AuditContext $context): PolicyEvaluation
    {
        $startTime = microtime(true);

        try {
            // Check cache
            $cacheKey = $this->generateEvaluationCacheKey($policy, $context);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Evaluate policy
            $result = $this->evaluator->evaluate($policy, $context);

            // Check compliance
            $compliance = $this->complianceChecker->check($policy, $result);

            // Create evaluation result
            $evaluation = new PolicyEvaluation([
                'policy' => $policy,
                'context' => $context,
                'result' => $result,
                'compliance' => $compliance,
                'duration' => microtime(true) - $startTime
            ]);

            // Cache result
            $this->cacheEvaluation($cacheKey, $evaluation);

            // Dispatch event
            $this->dispatcher->dispatch(new PolicyEvaluated($evaluation));

            return $evaluation;

        } catch (\Exception $e) {
            throw new PolicyEvaluationException(
                "Failed to evaluate policy: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function enforcePolicy(Policy $policy, AuditEvent $event): EnforcementResult
    {
        try {
            // Build context
            $context = $this->buildContext($event);

            // Evaluate policy
            $evaluation = $this->evaluatePolicy($policy, $context);

            // If policy passes, return success
            if ($evaluation->isPassing()) {
                return new EnforcementResult(true, $evaluation);
            }

            // If policy fails, execute enforcement actions
            $actions = $this->executeEnforcementActions($policy, $evaluation);

            return new EnforcementResult(false, $evaluation, $actions);

        } catch (\Exception $e) {
            throw new PolicyEnforcementException(
                "Failed to enforce policy: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function buildContext(AuditEvent $event): AuditContext
    {
        return new AuditContext([
            'event' => $event,
            'timestamp' => now(),
            'environment' => $this->getEnvironmentData(),
            'metadata' => $this->getContextMetadata($event)
        ]);
    }

    protected function executeEnforcementActions(
        Policy $policy,
        PolicyEvaluation $evaluation
    ): array {
        $results = [];

        foreach ($policy->getEnforcementActions() as $action) {
            try {
                $results[] = $action->execute($evaluation);
            } catch (\Exception $e) {
                $this->handleActionError($e, $action, $evaluation);
            }
        }

        return $results;
    }

    protected function generateEvaluationCacheKey(
        Policy $policy,
        AuditContext $context
    ): string {
        return sprintf(
            'policy_evaluation:%s:%s',
            $policy->getId(),
            md5(serialize($context))
        );
    }

    protected function cacheEvaluation(
        string $key,
        PolicyEvaluation $evaluation
    ): void {
        if ($evaluation->isCacheable()) {
            $this->cache->put(
                $key,
                $evaluation,
                config('audit.policy.cache_ttl', 3600)
            );
        }
    }

    protected function getEnvironmentData(): array
    {
        return [
            'environment' => config('app.env'),
            'server' => gethostname(),
            'php_version' => PHP_VERSION,
            'timestamp' => now()
        ];
    }

    protected function getContextMetadata(AuditEvent $event): array
    {
        return [
            'source' => $event->getSource(),
            'category' => $event->getCategory(),
            'severity' => $event->getSeverity(),
            'related_events' => $event->getRelatedEvents()
        ];
    }
}
