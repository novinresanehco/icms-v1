<?php

namespace App\Core\Validation;

class RuleValidationSystem
{
    private PatternMatcher $matcher;
    private StateValidator $validator;
    private ComplianceChecker $compliance;

    public function validateRules(): void
    {
        DB::transaction(function() {
            $this->validateSystemState();
            $this->enforceRules();
            $this->verifyCompliance();
            $this->updateValidationState();
        });
    }

    private function enforceRules(): void
    {
        foreach ($this->matcher->getActiveRules() as $rule) {
            if (!$this->validateRule($rule)) {
                throw new RuleValidationException("Rule validation failed: {$rule->getId()}");
            }
        }
    }

    private function validateRule(Rule $rule): bool
    {
        return $this->validator->validateRule($rule) &&
               $this->compliance->checkRule($rule);
    }

    private function verifyCompliance(): void
    {
        if (!$this->compliance->verify()) {
            throw new ComplianceException("Compliance verification failed");
        }
    }
}
