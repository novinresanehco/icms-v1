<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private RuleEngine $rules;
    private SecurityValidator $security;
    private LogManager $logs;
    private array $config;

    public function __construct(
        RuleEngine $rules,
        SecurityValidator $security,
        LogManager $logs,
        array $config
    ) {
        $this->rules = $rules;
        $this->security = $security;
        $this->logs = $logs;
        $this->config = $config;
    }

    public function validateOperation(Operation $operation, Context $context): ValidationResult
    {
        try {
            $this->validateContext($context);
            $this->validateSecurity($operation, $context);
            $this->validateBusinessRules($operation);
            $this->validateConstraints($operation);

            return new ValidationResult(true);
            
        } catch (ValidationException $e) {
            $this->handleValidationFailure($e, $operation, $context);
            throw $e;
        }
    }

    public function validateData(array $data, array $rules): array
    {
        $errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            try {
                $value = $data[$field] ?? null;
                $this->validateField($field, $value, $fieldRules);
            } catch (ValidationException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Data validation failed', [
                'errors' => $errors,
                'data' => $data,
                'rules' => $rules
            ]);
        }

        return $data;
    }

    public function validateRequest(Request $request): void
    {
        $this->validateHeaders($request->headers());
        $this->validateInput($request->all());
        $this->validateSession($request->session());
        
        if ($request->hasFile('*')) {
            $this->validateFiles($request->allFiles());
        }
    }

    public function validateResponse(Response $response): void
    {
        $this->validateResponseFormat($response);
        $this->validateResponseSecurity($response);
        $this->validateResponseIntegrity($response);
    }

    protected function validateContext(Context $context): void
    {
        if (!$context->hasRequiredData()) {
            throw new ValidationException('Missing required context data');
        }

        if (!$context->isValid()) {
            throw new ValidationException('Invalid context state');
        }

        $this->validateContextSecurity($context);
    }

    protected function validateSecurity(Operation $operation, Context $context): void
    {
        if (!$this->security->validateOperation($operation, $context)) {
            throw new SecurityValidationException('Operation security validation failed');
        }
    }

    protected function validateBusinessRules(Operation $operation): void
    {
        $violations = $this->rules->validate($operation);
        
        if (!empty($violations)) {
            throw new BusinessRuleException('Business rule violations detected', [
                'violations' => $violations,
                'operation' => $operation
            ]);
        }
    }

    protected function validateConstraints(Operation $operation): void
    {
        foreach ($this->config['constraints'] as $constraint) {
            if (!$constraint->isSatisfied($operation)) {
                throw new ConstraintViolationException(
                    "Constraint violation: {$constraint->getMessage()}"
                );
            }
        }
    }

    protected function validateField(string $field, $value, array $rules): void
    {
        foreach ($rules as $rule) {
            $validator = $this->resolveValidator($rule);
            
            if (!$validator->isValid($value)) {
                throw new ValidationException(
                    "Field '{$field}' failed validation rule: {$rule}"
                );
            }
        }
    }

    protected function validateHeaders(array $headers): void
    {
        $requiredHeaders = $this->config['required_headers'];
        
        foreach ($requiredHeaders as $header) {
            if (!isset($headers[$header])) {
                throw new ValidationException("Missing required header: {$header}");
            }
        }
    }

    protected function validateInput(array $input): void
    {
        if ($this->config['sanitize_input']) {
            $input = $this->sanitizeInput($input);
        }

        $this->validateData($input, $this->rules->getInputRules());
    }

    protected function validateSession(array $session): void
    {
        if (!isset($session['user_id'])) {
            throw new ValidationException('Invalid session: missing user ID');
        }

        if (!$this->security->validateSession($session)) {
            throw new SecurityValidationException('Session security validation failed');
        }
    }

    protected function validateFiles(array $files): void
    {
        foreach ($files as $file) {
            if (!$this->isValidFile($file)) {
                throw new ValidationException('Invalid file upload detected');
            }
        }
    }

    protected function validateResponseFormat(Response $response): void
    {
        if (!$this->isValidResponseFormat($response)) {
            throw new ValidationException('Invalid response format');
        }
    }

    protected function validateResponseSecurity(Response $response): void
    {
        if (!$this->security->validateResponse($response)) {
            throw new SecurityValidationException('Response security validation failed');
        }
    }

    protected function validateResponseIntegrity(Response $response): void
    {
        if (!$this->verifyResponseIntegrity($response)) {
            throw new IntegrityException('Response integrity check failed');
        }
    }

    protected function validateContextSecurity(Context $context): void
    {
        if (!$this->security->validateContext($context)) {
            throw new SecurityValidationException('Context security validation failed');
        }
    }

    protected function resolveValidator(string $rule): Validator
    {
        return $this->rules->getValidator($rule);
    }

    protected function sanitizeInput(array $input): array
    {
        $sanitized = [];
        
        foreach ($input as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    protected function sanitizeValue($value): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeInput($value);
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    protected function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($value);
    }

    protected function isValidFile(UploadedFile $file): bool
    {
        return $file->isValid() && 
               in_array($file->getMimeType(), $this->config['allowed_mime_types']) &&
               $file->getSize() <= $this->config['max_file_size'];
    }

    protected function isValidResponseFormat(Response $response): bool
    {
        return true; // Implementation depends on response format requirements
    }

    protected function verifyResponseIntegrity(Response $response): bool
    {
        return true; // Implementation depends on integrity check requirements
    }

    protected function handleValidationFailure(
        ValidationException $e,
        Operation $operation,
        Context $context
    ): void {
        $this->logs->warning('Validation failed', [
            'exception' => $e->getMessage(),
            'operation' => get_class($operation),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

interface ValidationInterface
{
    public function validateOperation(Operation $operation, Context $context): ValidationResult;
    public function validateData(array $data, array $rules): array;
    public function validateRequest(Request $request): void;
    public function validateResponse(Response $response): void;
}
