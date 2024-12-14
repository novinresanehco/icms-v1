<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Validator;
use App\Core\Interfaces\ValidationInterface;
use App\Exceptions\ValidationException;
use App\Core\Logging\ValidationLogger;

class CoreValidationManager implements ValidationInterface
{
    private ValidationLogger $validationLogger;
    private array $validationConfig;
    
    public function __construct(ValidationLogger $validationLogger, array $validationConfig)
    {
        $this->validationLogger = $validationLogger;
        $this->validationConfig = $validationConfig;
    }

    public function validateData(array $data, string $context): array
    {
        // Get validation rules for context
        $rules = $this->getValidationRules($context);
        
        // Custom messages for better error reporting
        $messages = $this->getCustomMessages($context);
        
        try {
            // Core data validation
            $validator = Validator::make($data, $rules, $messages);
            
            if ($validator->fails()) {
                throw new ValidationException(
                    'Data validation failed: ' . json_encode($validator->errors()->all())
                );
            }
            
            // Domain-specific validation
            $validated = $validator->validated();
            $this->performDomainValidation($validated, $context);
            
            // Log successful validation
            $this->validationLogger->logSuccess($context);
            
            return $validated;
            
        } catch (\Throwable $e) {
            // Log validation failure with context
            $this->validationLogger->logFailure($e, [
                'context' => $context,
                'data' => $data,
                'rules' => $rules
            ]);
            
            throw $e;
        }
    }

    private function getValidationRules(string $context): array
    {
        if (!isset($this->validationConfig['rules'][$context])) {
            throw new ValidationException("No validation rules defined for context: {$context}");
        }

        return $this->validationConfig['rules'][$context];
    }

    private function getCustomMessages(string $context): array
    {
        return $this->validationConfig['messages'][$context] ?? [];
    }

    private function performDomainValidation(array $data, string $context): void
    {
        // Get domain-specific validation logic
        $domainValidators = $this->getDomainValidators($context);
        
        foreach ($domainValidators as $validator) {
            if (!$validator->validate($data)) {
                throw new ValidationException(
                    "Domain validation failed: {$validator->getError()}"
                );
            }
        }
    }

    private function getDomainValidators(string $context): array
    {
        // Return context-specific validators
        return $this->validationConfig['domainValidators'][$context] ?? [];
    }

    // CMS-specific validation methods
    public function validateContentType(array $content): void
    {
        $requiredFields = [
            'title', 'body', 'status', 'author_id'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($content[$field])) {
                throw new ValidationException("Missing required content field: {$field}");
            }
        }

        // Validate content format
        if (!$this->isValidContentFormat($content['body'])) {
            throw new ValidationException('Invalid content format');
        }
    }

    public function validateMediaType(array $media): void
    {
        $allowedTypes = $this->validationConfig['allowedMediaTypes'];
        
        if (!in_array($media['mime_type'], $allowedTypes)) {
            throw new ValidationException('Unsupported media type');
        }
        
        // Validate media size
        if ($media['size'] > $this->validationConfig['maxMediaSize']) {
            throw new ValidationException('Media size exceeds limit');
        }
    }

    private function isValidContentFormat(string $content): bool
    {
        // Implement content format validation logic
        return true; // Placeholder - implement actual validation
    }
}
