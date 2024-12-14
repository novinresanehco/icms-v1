<?php

namespace App\Core\Audit;

class AuditComplianceChecker
{
    private RuleEngine $ruleEngine;
    private PolicyResolver $policyResolver;
    private ComplianceValidator $validator;
    private ReportGenerator $reportGenerator;
    private LoggerInterface $logger;

    public function __construct(
        RuleEngine $ruleEngine,
        PolicyResolver $policyResolver,
        ComplianceValidator $validator,
        ReportGenerator $reportGenerator,
        LoggerInterface $logger
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->policyResolver = $policyResolver;
        $this->validator = $validator;
        $this->reportGenerator = $reportGenerator;
        $this->logger = $logger;
    }

    public function checkCompliance(AuditEvent $event): ComplianceResult
    {
        try {
            // Get applicable policies
            $policies = $this->policyResolver->resolveApplicablePolicies($event);

            // Check each policy
            $checks = [];
            foreach ($policies as $policy) {
                $checks[] = $this->checkPolicy($policy, $event);
            }

            // Aggregate results
            $result = $this->aggregateResults($checks);

            // Generate report
            $report = $this->generateReport($result, $event);

            return new ComplianceResult(
                $result->isCompliant(),
                $checks,
                $report
            );

        } catch (\Exception $e) {
            throw new ComplianceCheckException(
                "Failed to check compliance: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function validateCompliance(array $data): ValidationResult
    {
        try {
            // Validate data structure
            $this->validator->validateStructure($data);

            // Validate content
            $this->validator->validateContent($data);

            // Validate relationships
            $this->validator->validateRelationships($data);

            return new ValidationResult(true);

        } catch (ValidationException $e) {
            return new ValidationResult(false, $e->getViolations());
        }
    }

    protected function checkPolicy(Policy $policy, AuditEvent $event): PolicyCheck
    {
        $startTime = microtime(true);

        try {
            // Build check context
            $context = $this->buildContext($policy, $event);

            // Evaluate rules
            $ruleResults = $this->evaluateRules($policy, $context);

            // Check constraints
            $constraintResults = $this->checkConstraints($policy, $context);

            // Create policy check result
            return new PolicyCheck(
                $policy,
                $ruleResults,
                $constraintResults,
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            $this->logger->error('Policy check failed', [
                'policy_id' => $policy->getId(),
                'event_id' => $event->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    protected function evaluateRules(Policy $policy, CheckContext $context): array
    {
        $results = [];

        foreach ($policy->getRules() as $rule) {
            try {
                $results[] = $this->ruleEngine->evaluate($rule, $context);
            } catch (\Exception $e) {
                $this->handleRuleError($e, $rule, $context);
            }
        }

        return $results;
    }

    protected function checkConstraints(Policy $policy, CheckContext $context): array
    {
        $results = [];

        foreach ($policy->getConstraints() as $constraint) {
            try {
                $results[] = $this->evaluateConstraint($constraint, $context);
            } catch (\Exception $e) {
                $this->handleConstraintError($e, $constraint, $context);
            }
        }

        return $results;
    }

    protected function evaluateConstraint(
        Constraint $constraint,
        CheckContext $context
    ): ConstraintResult {
        $startTime = microtime(true);

        try {
            $evaluation = $constraint->evaluate($context);

            return new ConstraintResult(
                $constraint,
                $evaluation,
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            throw new ConstraintEvaluationException(
                "Failed to evaluate constraint: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function aggregateResults(array $checks): AggregatedResult
    {
        $violations = [];
        $warnings = [];
        $compliant = true;

        foreach ($checks as $check) {
            if (!$check->isCompliant()) {
                $compliant = false;
                $violations = array_merge($violations, $check->getViolations());
            }

            $warnings = array_merge($warnings, $check->getWarnings());
        }

        return new AggregatedResult(
            $compliant,
            $violations,
            $warnings
        );
    }

    protected function generateReport(
        AggregatedResult $result,
        AuditEvent $event
    ): ComplianceReport {
        return $this->reportGenerator->generate([
            'event' => $event,
            'result' => $result,
            'timestamp' => now(),
            'metadata' => $this->getReportMetadata($event)
        ]);
    }

    protected function buildContext(Policy $policy, AuditEvent $event): CheckContext
    {
        return new CheckContext([
            'policy' => $policy,
            'event' => $event,
            'timestamp' => now(),
            'environment' => $this->getEnvironmentData(),
            'metadata' => $this->getContextMetadata($event)
        ]);
    }

    protected function getReportMetadata(AuditEvent $event): array
    {
        return [
            'event_type' => $event->getType(),
            'source' => $event->getSource(),
            'severity' => $event->getSeverity(),
            'timestamp' => $event->getTimestamp(),
            'environment' => config('app.env')
        ];
    }
}
