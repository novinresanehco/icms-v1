<?php

namespace App\Core\Security;

class CriticalDataValidator {
    private SecurityConfig $config;
    private ValidationRules $rules;
    private MonitorService $monitor;

    public function validateCritical(array $data, string $context): bool {
        try {
            // Security validation first
            if(!$this->validateSecurity($data)) {
                throw new SecurityValidationException();
            }

            // Content validation
            if(!$this->validateContent($data, $context)) {
                throw new ContentValidationException();
            }

            // Integrity check
            if(!$this->checkIntegrity($data)) {
                throw new IntegrityException();
            }

            return true;

        } catch (\Exception $e) {
            $this->monitor->logValidationFailure($e);
            throw $e;
        }
    }

    private function validateSecurity(array $data): bool {
        // XSS prevention
        foreach($data as $key => $value) {
            if($this->containsXSS($value)) {
                return false;
            }
        }

        // SQL injection check 
        if($this->containsSQLInjection($data)) {
            return false;
        }

        return true;
    }

    private function validateContent(array $data, string $context): bool {
        $rules = $this->rules->getForContext($context);
        foreach($rules as $field => $rule) {
            if(!$this->validateField($data[$field], $rule)) {
                return false;
            }
        }
        return true;
    }

    private function checkIntegrity(array $data): bool {
        $hash = $data['_hash'] ?? '';
        unset($data['_hash']);
        return hash_equals(
            $this->generateHash($data),
            $hash
        );
    }
}
