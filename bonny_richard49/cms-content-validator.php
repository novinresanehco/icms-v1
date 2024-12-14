<?php

namespace App\Core\CMS\Validation;

class ContentValidator implements ContentValidationInterface
{
    private SecurityService $security;
    private ValidationRules $rules;
    private ContentSanitizer $sanitizer;
    private AuditLogger $logger;

    private array $securityRules = [
        'allowedTags' => ['p', 'a', 'b', 'i', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
        'allowedAttributes' => ['href', 'title', 'class'],
        'urlSchemes' => ['http', 'https', 'mailto'],
        'maxLength' => 50000,
        'maxLinks' => 100,
        'maxImages' => 50,
    ];

    public function validateForCreation(array $data): ValidationResult
    {
        $operationId = uniqid('content_validation_', true);

        try {
            $this->validateStructure($data);
            $this->validateSecurity($data);
            $this->validateBusinessRules($data);
            $this->validateCompliance($data);

            return new ValidationResult(true);

        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, 'creation', $data, $operationId);
            throw $e;
        }
    }

    public function validateForUpdate(array $data, Content $existing): ValidationResult
    {
        $operationId = uniqid('content_update_validation_', true);

        try {
            $this->validateUpdateStructure($data, $existing);
            $this->validateSecurity($data);
            $this->validateUpdateBusinessRules($data, $existing);
            $this->validateCompliance($data);

            return new ValidationResult(true);

        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, 'update', $data, $operationId);
            throw $e;
        }
    }

    public function sanitize(string $content): string
    {
        $operationId = uniqid('content_sanitize_', true);

        try {
            $sanitized = $this->sanitizer->sanitize($content, $this->securityRules);
            $this->validateSanitizedContent($sanitized);
            return $sanitized;

        } catch (\Throwable $e) {
            $this->handleSanitizationFailure($e, $content, $operationId);
            throw $e;
        }
    }

    protected function validateStructure(array $data): void
    {
        // Validate required fields
        $this->validateRequiredFields($data);

        // Validate field types
        $this->validateFieldTypes($data);

        // Validate field lengths
        $this->validateFieldLengths($data);

        // Validate relationships
        $this->validateRelationships($data);
    }

    protected function validateUpdateStructure(array $data, Content $existing): void
    {
        // Validate immutable fields not changed
        $this->validateImmutableFields($data, $existing);

        // Validate version control
        $this->validateVersionControl($data, $existing);

        // Validate update-specific rules
        $this->validateUpdateRules($data, $existing);
    }

    protected function validateSecurity(array $data): void
    {
        // Validate content security
        if (isset($data['content'])) {
            $this->validateContentSecurity($data['content']);
        }

        // Validate metadata security
        if (isset($data['meta'])) {
            $this->validateMetaSecurity($data['meta']);
        }

        // Validate file security
        if (isset($data['files'])) {
            $this->validateFileSecurity($data['files']);
        }

        // Check for malicious content
        $this->security->scanForThreats($data);
    }

    protected function validateBusinessRules(array $data): void
    {
        $this->rules->validateContentRules($data);
        $this->validateWorkflow($data);
        $this->validateCategoryRules($data);
        $this->validateTagRules($data);
    }

    protected function validateCompliance(array $data): void
    {
        // Validate privacy compliance
        if (isset($data['personal_data'])) {
            $this->validatePrivacyCompliance($data['personal_data']);
        }

        // Validate regulatory compliance
        $this->validateRegulatoryCompliance($data);

        // Validate retention policies
        $this->validateRetentionPolicies($data);
    }

    protected function validateContentSecurity(string $content): void
    {
        // Check for XSS vulnerabilities
        $this->security->validateXssSecurity($content);

        // Validate embedded content
        $this->security->validateEmbeddedContent($content);

        // Check content size limits
        if (strlen($content) > $this->securityRules['maxLength']) {
            throw new SecurityValidationException('Content exceeds maximum length');
        }

        // Validate allowed tags and attributes
        $this->validateAllowedElements($content);
    }

    protected function handleValidationFailure(
        \Throwable $e,
        string $type,
        array $data,
        string $operationId
    ): void {
        $this->logger->logFailure([
            'type' => 'content_validation_failure',
            'validation_type' => $type,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'data' => $this->sanitizeLogData($data),
            'severity' => $this->determineErrorSeverity($e)
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $type, $data, $operationId);
        }
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        string $type,
        array $data,
        string $operationId
    ): void {
        $this->logger->logCritical([
            'type' => 'critical_content_validation_failure',
            'validation_type' => $type,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'data' => $this->sanitizeLogData($data),
            'severity' => 'CRITICAL'
        ]);
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityValidationException ||
               $e instanceof CriticalValidationException;
    }

    protected function determineErrorSeverity(\Throwable $e): string
    {
        if ($e instanceof SecurityValidationException) {
            return 'CRITICAL';
        }

        if ($e instanceof BusinessRuleException) {
            return 'ERROR';
        }

        return 'WARNING';
    }

    protected function sanitizeLogData(array $data): array
    {
        // Remove sensitive data before logging
        unset($data['password']);
        unset($data['token']);
        unset($data['auth']);

        return $data;
    }
}
