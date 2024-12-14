// File: app/Core/Validation/Manager/ValidationManager.php
<?php

namespace App\Core\Validation\Manager;

class ValidationManager
{
    protected RuleRegistry $ruleRegistry;
    protected ValidatorFactory $validatorFactory;
    protected ErrorCollector $errorCollector;
    protected ValidationCache $cache;

    public function validate(array $data, array $rules): ValidationResult
    {
        $validator = $this->validatorFactory->make($data, $rules);
        
        try {
            $validator->validate();
            return new ValidationResult(true);
        } catch (ValidationException $e) {
            $this->errorCollector->collect($e->getErrors());
            return new ValidationResult(false, $e->getErrors());
        }
    }

    public function addRule(string $name, Rule $rule): void
    {
        $this->ruleRegistry->register($name, $rule);
    }

    public function extend(string $name, \Closure $callback): void
    {
        $this->ruleRegistry->extend($name, $callback);
    }
}

// File: app/Core/Validation/Rules/RuleRegistry.php
<?php

namespace App\Core\Validation\Rules;

class RuleRegistry
{
    protected array $rules = [];
    protected array $extensions = [];
    protected RuleValidator $validator;

    public function register(string $name, Rule $rule): void
    {
        $this->validator->validateRule($rule);
        $this->rules[$name] = $rule;
    }

    public function extend(string $name, \Closure $callback): void
    {
        $this->extensions[$name] = $callback;
    }

    public function getRule(string $name): Rule
    {
        if (isset($this->rules[$name])) {
            return $this->rules[$name];
        }

        if (isset($this->extensions[$name])) {
            return new CustomRule($name, $this->extensions[$name]);
        }

        throw new RuleNotFoundException("Rule not found: {$name}");
    }
}

// File: app/Core/Validation/Rules/Rule.php
<?php

namespace App\Core\Validation\Rules;

abstract class Rule
{
    protected string $message;
    protected array $parameters = [];

    abstract public function passes($attribute, $value): bool;

    public function message(): string
    {
        return $this->message;
    }

    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    protected function getParameter(string $key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }
}

// File: app/Core/Validation/Validator/ValidatorFactory.php
<?php

namespace App\Core\Validation\Validator;

class ValidatorFactory
{
    protected RuleRegistry $ruleRegistry;
    protected MessageResolver $messageResolver;
    protected ValidatorConfig $config;

    public function make(array $data, array $rules): Validator
    {
        return new Validator(
            $data,
            $this->parseRules($rules),
            $this->messageResolver,
            $this->config
        );
    }

    protected function parseRules(array $rules): array
    {
        $parsed = [];

        foreach ($rules as $attribute => $ruleSet) {
            $parsed[$attribute] = $this->parseRuleSet($ruleSet);
        }

        return $parsed;
    }

    protected function parseRuleSet($ruleSet): array
    {
        if (is_string($ruleSet)) {
            $ruleSet = explode('|', $ruleSet);
        }

        return array_map(function($rule) {
            return $this->parseRule($rule);
        }, $ruleSet);
    }
}
