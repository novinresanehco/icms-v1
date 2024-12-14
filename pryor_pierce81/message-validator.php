<?php

namespace App\Core\Message;

class MessageValidator implements MessageValidatorInterface
{
    private SchemaValidator $schemaValidator;
    private IntegrityChecker $integrityChecker;
    private SecurityValidator $securityValidator;
    private MessageLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        SchemaValidator $schemaValidator,
        IntegrityChecker $integrityChecker,
        SecurityValidator $securityValidator,
        MessageLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->schemaValidator = $schemaValidator;
        $this->integrityChecker = $integrityChecker;
        $this->securityValidator = $securityValidator;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function validateMessage(Message $message): ValidationResult
    {
        $validationId = $this->initializeValidation($message);
        
        try {
            DB::beginTransaction();

            $this->validateSchema($message);
            $this->checkIntegrity($message);
            $this->validateSecurity($message);

            $result = new ValidationResult([
                'validationId' => $validationId,
                'message' => $message,
                'status' => ValidationStatus::PASSED,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validateSchema(Message $message): void
    {
        $violations = $this->schemaValidator->validate($message);
        
        if (!empty($violations)) {
            $this->emergency->handleSchemaViolations($violations);
            throw new SchemaValidationException(
                'Message schema validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function checkIntegrity(Message $message): void
    {
        if (!$this->integrityChecker->verify($message)) {
            $this->emergency->handleIntegrityFailure($message);
            throw new IntegrityException('Message integrity check failed');
        }
    }

    private function validateSecurity(Message $message): void
    {
        $securityIssues = $this->securityValidator->validate($message);
        
        if (!empty($securityIssues)) {
            foreach ($securityIssues as $issue) {
                if ($issue->isCritical()) {
                    $this->emergency->handleCriticalSecurityIssue($issue);
                }
            }
            throw new SecurityValidationException('Message security validation failed');
        }
    }

    private function handleValidationFailure(
        ValidationException $e,
        string $validationId
    ): void {
        $this->logger->logFailure($e, $validationId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateEmergencyProtocol();
            $this->alerts->dispatchCriticalAlert(
                new ValidationFailureAlert($e, $validationId)
            );
        }
    }
}
