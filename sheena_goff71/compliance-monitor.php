<?php

namespace App\Core\Compliance;

class ComplianceMonitor
{
    private ComplianceChecker $checker;
    private ViolationHandler $handler;
    private AuditLogger $logger;

    public function monitorCompliance(): void
    {
        DB::transaction(function() {
            $this->validateCurrentState();
            $this->checkViolations();
            $this->enforceCompliance();
            $this->logComplianceState();
        });
    }

    private function validateCurrentState(): void
    {
        if (!$this->checker->validateState()) {
            throw new ComplianceException("Invalid compliance state");
        }
    }

    private function checkViolations(): void
    {
        $violations = $this->checker->detectViolations();
        if (!empty($violations)) {
            $this->handler->handleViolations($violations);
            throw new ViolationException("Compliance violations detected");
        }
    }

    private function enforceCompliance(): void
    {
        $this->checker->enforceRules();
        $this->logger->logEnforcement();
    }
}
