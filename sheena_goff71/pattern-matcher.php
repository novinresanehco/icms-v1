<?php

namespace App\Core\Validation;

class PatternMatcher 
{
    private array $criticalPatterns;
    private PatternValidator $validator;
    private MatchingEngine $engine;

    public function getCriticalPatterns(): array
    {
        return array_map(
            fn($pattern) => $this->validatePattern($pattern),
            $this->criticalPatterns
        );
    }

    private function validatePattern(array $pattern): ValidationPattern
    {
        if (!$this->validator->isValid($pattern)) {
            throw new PatternException("Invalid pattern structure");
        }
        return new ValidationPattern($pattern);
    }

    private function matchPattern(ValidationPattern $pattern): bool 
    {
        return $this->engine->match($pattern);
    }
}

class ProtectionSystem 
{
    private SecurityManager $security;
    
    public function enforcePattern(ValidationPattern $pattern): void 
    {
        $this->security->enforcePattern($pattern);
        $this->validatePatternEnforcement();
    }

    public function enableMaximumSecurity(): void 
    {
        $this->security->enableMaxProtection();
        $this->verifyProtectionLevel();
    }
}
