<?php

namespace App\Core\Schema;

class SchemaValidationService implements SchemaValidatorInterface
{
    private SchemaRegistry $registry;
    private ValidationEngine $engine;
    private IntegrityChecker $integrityChecker;
    private SchemaLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        SchemaRegistry $registry,
        ValidationEngine $engine,
        IntegrityChecker $integrityChecker,
        SchemaLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->registry = $registry;
        $this->engine = $engine;
        $this->integrityChecker = $integrityChecker;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function validateSchema(ValidationContext $context): ValidationResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $schema = $this->loadSchema($context);
            $this->validateSchemaIntegrity($schema);

            $data = $this->prepareData($context);
            $this->validateData($data, $schema);

            $result = new ValidationResult([
                'validationId' => $validationId,
                'schema' => $schema,
                'validatedData' => $data,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (SchemaException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalSchemaException($e->getMessage(), $e);
        }
    }

    private function loadSchema(ValidationContext $context): Schema
    {
        $schema = $this->registry->getSchema($context->getSchemaIdentifier());
        
        if (!$schema) {
            throw new SchemaNotFoundException('Required schema not found');
        }
        
        return $schema;
    }

    private function validateSchemaIntegrity(Schema $schema): void
    {
        if (!$this->integrityChecker->verifyIntegrity($schema)) {
            $this->emergency->handleSchemaIntegrityFailure($schema);
            throw new SchemaIntegrityException('Schema integrity check failed');
        }
    }

    private function validateData(array $data, Schema $schema): void
    {
        $violations = $this->engine->validate($data, $schema);
        
        if (!empty($violations)) {
            $this->handleValidationViolations($violations);
        }
    }

    private function handleValidationViolations(array $violations): void
    {
        $this->logger->logViolations($violations);
        
        $criticalViolations = array_filter(
            $violations,
            fn($v) => $v->isCritical()
        );
        
        if (!empty($criticalViolations)) {
            $this->emergency->handleCriticalViolations($criticalViolations);
            throw new CriticalValidationException(
                'Critical schema violations detected',
                ['violations' => $criticalViolations]
            );
        }
    }
}
