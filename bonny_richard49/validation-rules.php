<?php

namespace App\Core\Validation;

class ValidationRules implements ValidationRulesInterface
{
    private RuleRegistry $registry;
    private RuleValidator $validator;
    private AuditLogger $logger;

    public function validateRule(string $rule, $value, array $params = []): bool 
    {
        $operationId = $this->logger->startOperation('rule_validation');

        try {
            $this->validateRuleExists($rule);
            $this->validateRuleParams($rule, $params);
            
            $valid = $this->executeRuleValidation($rule, $value, $params);
            
            $this->logValidation($operationId, $rule, $valid);
            
            return $valid;

        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, $operationId, $rule);
            throw $e;
        }
    }

    public function registerRule(string $name, callable $validator, array $config = []): void 
    {
        $operationId = $this->logger->startOperation('rule_registration');

        try {
            $this->validateRuleConfig($name, $config);
            $this->validateRuleCallback($validator);
            
            $this->registry->register($name, [
                'validator' => $validator,
                'config' => $config
            ]);

            $this->logger->logSuccess($operationId);

        } catch (\Throwable $e) {
            $this->handleRegistrationFailure($e, $operationId, $name);
            throw $e;
        }
    }

    protected function executeRuleValidation(string $rule, $value, array $params): bool
    {
        $ruleConfig = $this->registry->getRule($rule);
        
        return $this->validator->validate(
            $value,
            $ruleConfig['validator'],
            array_merge($ruleConfig['config'], $params)
        );
    }

    protected function validateRuleExists(string $rule): void
    {
        if (!$this->registry->hasRule($rule)) {
            throw new ValidationException("Rule not registered: {$rule}");
        }
    }

    protected function validateRuleParams(string $rule, array $params): void
    {
        $ruleConfig = $this->registry->getRule($rule);
        
        if (!$this->validator->validateRuleParams($params, $ruleConfig['config'])) {
            throw new ValidationException("Invalid rule parameters for {$rule}");
        }
    }

    protected function validateRuleConfig(string $name, array $config): void
    {
        if (!$this->validator->validateRuleConfig($config)) {
            throw new ValidationException("Invalid rule configuration for {$name}");
        }
    }

    protected function validateRuleCallback(callable $validator): void
    {
        if (!$this->validator->validateRuleCallback($validator)) {
            throw new ValidationException('Invalid rule validator callback');
        }
    }

    protected function logValidation(string $operationId, string $rule, bool $valid): void
    {
        $this->logger->logValidation([
            'operation_id' => $operationId,
            'rule' => $rule,
            'valid' => $valid,
            'timestamp' => time()
        ]);
    }

    protected function handleValidationFailure(
        \Throwable $e,
        string $operationId,
        string $rule
    ): void {
        $this->logger->logFailure([
            'operation_id' => $operationId,
            'rule' => $rule,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);
    }

    protected function handleRegistrationFailure(
        \Throwable $e,
        string $operationId,
        string $name
    ): void {
        $this->logger->logFailure([
            'operation_id' => $operationId,
            'rule' => $name,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);
    }
}
