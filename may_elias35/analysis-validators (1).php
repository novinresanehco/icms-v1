<?php

namespace App\Core\Audit\Validators;

class DataValidator
{
    private ValidationConfig $config;
    private array $errors = [];

    public function __construct(ValidationConfig $config)
    {
        $this->config = $config;
    }

    public function validate(array $data): ValidationResult
    {
        $this->errors = [];

        $this->validateStructure($data);
        $this->validateDataTypes($data);
        $this->validateRanges($data);
        $this->validateRelationships($data);
        $this->validateConsistency($data);

        return new ValidationResult([
            'is_valid' => empty($this->errors),
            'errors' => $this->errors
        ]);
    }

    private function validateStructure(array $data): void
    {
        foreach ($this->config->getRequiredFields() as $field) {
            if (!$this->validateField($data, $field)) {
                $this->errors[] = "Missing required field: {$field}";
            }
        }
    }

    private function validateDataTypes(array $data): void
    {
        foreach ($this->config->getFieldTypes() as $field => $type) {
            foreach ($data as $row) {
                if (isset($row[$field]) && !$this->validateType($row[$field], $type)) {
                    $this->errors[] = "Invalid type for field {$field}: expected {$type}";
                }
            }
        }
    }

    private function validateRanges(array $data): void
    {
        foreach ($this->config->getFieldRanges() as $field => $range) {
            foreach ($data as $row) {
                if (isset($row[$field]) && !$this->validateRange($row[$field], $range)) {
                    $this->errors[] = "Value out of range for field {$field}";
                }
            }
        }
    }

    private function validateRelationships(array $data): void
    {
        foreach ($this->config->getRelationships() as $relationship) {
            if (!$this->validateRelationship($data, $relationship)) {
                $this->errors[] = "Invalid relationship: {$relationship['name']}";
            }
        }
    }

    private function validateConsistency(array $data): void
    {
        foreach ($this->config->getConsistencyRules() as $rule) {
            if (!$this->validateConsistencyRule($data, $rule)) {
                $this->errors[] = "Consistency rule violation: {$rule['name']}";
            }
        }
    }
}

class ValidationResult
{
    private bool $isValid;
    private array $errors;

    public function __construct(array $result)
    {
        $this->isValid = $result['is_valid'];
        $this->errors = $result['errors'];
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasError(string $type): bool
    {
        return array_any($this->errors, fn($error) => str_contains($error, $type));
    }

    public function getErrorsByType(string $type): array
    {
        return array_filter($this->errors, fn($error) => str_contains($error, $type));
    }
}

class AnalysisValidator
{
    private array $validators;
    private array $errors = [];

    public function __construct(array $validators)
    {
        $this->validators = $validators;
    }

    public function validate(AnalysisRequest $request): ValidationResult
    {
        $this->errors = [];

        $this->validateConfig($request->getConfig());
        $this->validateData($request->getData());
        $this->validateParameters($request->getParameters());
        $this->validateConstraints($request);

        return new ValidationResult([
            'is_valid' => empty($this->errors),
            'errors' => $this->errors
        ]);
    }

    private function validateConfig(AnalysisConfig $config): void
    {
        foreach ($this->validators['config'] as $validator) {
            $result = $validator->validate($config);
            if (!$result->isValid()) {
                $this->errors = array_merge($this->errors, $result->getErrors());
            }
        }
    }

    private function validateData(array $data): void
    {
        foreach ($this->validators['data'] as $validator) {
            $result = $validator->validate($data);
            if (!$result->isValid()) {
                $this->errors = array_merge($this->errors, $result->getErrors());
            }
        }
    }

    private function validateParameters(array $parameters): void
    {
        foreach ($this->validators['parameters'] as $validator) {
            $result = $validator->validate($parameters);
            if (!$result->isValid()) {
                $this->errors = array_merge($this->errors, $result->getErrors());
            }
        }
    }

    private function validateConstraints(AnalysisRequest $request): void
    {
        foreach ($this->validators['constraints'] as $validator) {
            $result = $validator->validate($request);
            if (!$result->isValid()) {
                $this->errors = array_merge($this->errors, $result->getErrors());
            }
        }
    }
}

class ConfigValidator
{
    private array $requiredSections = [
        'statistical',
        'pattern',
        'trend',
        'anomaly',
        'processing'
    ];

    public function validate(AnalysisConfig $config): ValidationResult
    {
        $errors = [];

        foreach ($this->requiredSections as $section) {
            if (!$this->validateSection($config, $section)) {
                $errors[] = "Missing or invalid configuration section: {$section}";
            }
        }

        return new ValidationResult([
            'is_valid' => empty($errors),
            'errors' => $errors
        ]);
    }

    private function validateSection(AnalysisConfig $config, string $section): bool
    {
        $method = "get{$section}Config";
        if (!method_exists($config, $method)) {
            return false;
        }

        $sectionConfig = $config->$method();
        return $this->validateSectionConfig($sectionConfig, $section);
    }

    private function validateSectionConfig($config, string $section): bool
    {
        $validator = $this->getValidatorForSection($section);
        return $validator->validate($config)->isValid();
    }

    private function getValidatorForSection(string $section): BaseValidator
    {
        $validatorClass = "App\\Core\\Audit\\Validators\\{$section}Validator";
        return new $validatorClass();
    }
}

abstract class BaseValidator
{
    protected array $errors = [];

    public function validate($data): ValidationResult
    {
        $this->errors = [];
        $this->doValidate($data);

        return new ValidationResult([
            'is_valid' => empty($this->errors),
            'errors' => $this->errors
        ]);
    }

    abstract protected function doValidate($data): void;

    protected function addError(string $error): void
    {
        $this->errors[] = $error;
    }
}
