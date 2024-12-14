// File: app/Core/Workflow/Validation/WorkflowValidator.php
<?php

namespace App\Core\Workflow\Validation;

class WorkflowValidator
{
    protected StateValidator $stateValidator;
    protected TransitionValidator $transitionValidator;
    protected SchemaValidator $schemaValidator;

    public function validate(Workflow $workflow): bool
    {
        // Validate states
        $this->validateStates($workflow->getStates());
        
        // Validate transitions
        $this->validateTransitions($workflow->getTransitions());
        
        // Validate workflow schema
        $this->validateSchema($workflow);
        
        // Validate reachability
        $this->validateReachability($workflow);
        
        return true;
    }

    protected function validateStates(array $states): void
    {
        foreach ($states as $state) {
            $this->stateValidator->validate($state);
        }
    }

    protected function validateReachability(Workflow $workflow): void
    {
        $unreachableStates = $this->findUnreachableStates($workflow);
        
        if (!empty($unreachableStates)) {
            throw new ValidationException("Unreachable states found: " . implode(', ', $unreachableStates));
        }
    }
}
