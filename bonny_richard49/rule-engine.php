<?php

namespace App\Core\Analysis;

class RuleEngine implements RuleEngineInterface
{
    private RuleRegistry $registry;
    private RuleExecutor $executor;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function executeRules(
        string $context, 
        array $data, 
        array $options = []
    ): RuleExecutionResult {
        $operationId = uniqid('rule_', true);

        try {
            $this->validateContext($context);
            $this->validateData($data);
            
            $rules = $this->registry->getRulesForContext($context);
            $results = $this->executeRuleSet($rules, $data, $options);
            
            return $this->compileResults($results, $operationId);

        } catch (\Throwable $e) {
            $this->handleRuleFailure($e, $context, $operationId);
            throw $e;
        }
    }

    protected function executeRuleSet(
        array $rules,
        array $data,
        array $options
    ): array {
        $results = [];
        foreach ($rules as $rule) {
            $results[$rule->getId()] = $this->executeRule($rule, $data, $options);
        }
        return $results;
    }

    protected function executeRule(
        Rule $rule,
        array $data,
        array $options
    ): RuleResult {
        try {
            $this->validateRule($rule);
            return $this->executor->execute($rule, $data, $options);
        } catch (\Throwable $e) {
            return $this->handleRuleExecutionFailure($e, $rule);
        }
    }

    protected function validateContext(string $context): void
    {
        if (!$this->validator->validateRuleContext($context)) {
            throw new RuleEngineException('Invalid rule context');
        }
    }

    protected function validateData(array $data): void
    {
        if (!$this->validator->validateRuleData($data)) {
            throw new RuleEngineException('Invalid rule data');
        }
    }

    protected function validateRule(Rule $rule): void
    {
        if (!$this->validator->validateRule($rule)) {
            throw new RuleEngineException('Invalid rule configuration');
        }
    }

    protected function compileResults(array $results, string $operationId): RuleExecutionResult
    {
        return new RuleExecutionResult([
            'results' => $results,
            'operation_id' => $operationId,
            'timestamp' => time(),
            'status' => $this->determineExecutionStatus($results)
        ]);
    }

    protected function determineExecutionStatus(array $results): string
    {
        if ($this->hasCriticalFailures($results)) {
            return RuleExecutionResult::STATUS_CRITICAL;
        }

        if ($this->hasFailures($results)) {
            return RuleExecutionResult::STATUS_FAILED;
        }

        if ($this->hasWarnings($results)) {
            return RuleExecutionResult::STATUS_WARNING;
        }

        return RuleExecutionResult::STATUS_PASSED;
    }

    protected function hasCriticalFailures(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->isCriticalFailure()) {
                return true;
            }
        }
        return false;
    }

    protected function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->isFailure()) {
                return true;
            }
        }
        return false;
    }

    protected function hasWarnings(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->hasWarnings()) {
                return true;
            }
        }
        return false;
    }

    protected function handleRuleFailure(
        \Throwable $e,
        string $context,
        string $operationId
    ): void {
        $this->logger->logFailure([
            'type' => 'rule_execution_failure',
            'context' => $context,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->escalateFailure($e, $context, $operationId);
        }
    }

    protected function handleRuleExecutionFailure(\Throwable $e, Rule $rule): RuleResult
    {
        $this->logger->logError([
            'type' => 'individual_rule_failure',
            'rule_id' => $rule->getId(),
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);

        return new RuleResult([
            'status' => RuleResult::STATUS_FAILED,
            'error' => $e->getMessage()
        ]);
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalRuleException ||
               $e instanceof SecurityRuleException;
    }

    protected function escalateFailure(
        \Throwable $e,
        string $context,
        string $operationId
    ): void {
        $this->logger->logCritical([
            'type' => 'critical_rule_failure',
            'context' => $context,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'severity' => 'CRITICAL'
        ]);
    }
}
