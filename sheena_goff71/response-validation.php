<?php

namespace App\Core\Validation;

class ResponseValidationSystem
{
    private ResponseChecker $checker;
    private ComplianceVerifier $compliance;
    private SecurityValidator $security;

    public function validateResponse(IncidentResponse $response): void
    {
        DB::transaction(function() use ($response) {
            $this->validateResponseStructure($response);
            $this->validateSecurityMeasures($response);
            $this->verifyCompliance($response);
            $this->validateOutcome($response);
        });
    }

    private function validateResponseStructure(IncidentResponse $response): void
    {
        if (!$this->checker->validateStructure($response)) {
            throw new ValidationException("Response structure validation failed");
        }
    }

    private function validateSecurityMeasures(IncidentResponse $response): void
    {
        if (!$this->security->validateMeasures($response)) {
            throw new SecurityException("Response security validation failed");
        }
    }

    private function validateOutcome(IncidentResponse $response): void
    {
        $outcome = $response->getOutcome();
        if (!$this->checker->validateOutcome($outcome)) {
            throw new ValidationException("Response outcome validation failed");
        }
    }
}
