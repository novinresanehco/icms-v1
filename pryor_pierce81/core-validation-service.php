namespace App\Core\Validation;

class ValidationService implements ValidationInterface
{
    private DataSanitizer $sanitizer;
    private RuleRegistry $rules;
    private ValidationLogger $logger;
    private CacheManager $cache;

    public function validateInput(array $data, array $rules): ValidationResult
    {
        DB::beginTransaction();
        
        try {
            $sanitizedData = $this->sanitizer->sanitize($data);
            
            $validationContext = new ValidationContext([
                'original_data' => $data,
                'sanitized_data' => $sanitizedData,
                'rules' => $rules,
                'timestamp' => time()
            ]);

            foreach ($rules as $field => $fieldRules) {
                $this->validateField(
                    $field, 
                    $sanitizedData[$field] ?? null,
                    $fieldRules,
                    $validationContext
                );
            }

            $this->validateCrossFieldRules($sanitizedData, $rules);
            
            $this->validateBusinessRules($sanitizedData, $validationContext);

            $result = new ValidationResult(true, $sanitizedData);
            
            DB::commit();
            $this->logger->logSuccess($validationContext);
            
            return $result;
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->logger->logFailure($e, $validationContext);
            throw $e;
        }
    }

    private function validateField(
        string $field,
        mixed $value,
        array $rules,
        ValidationContext $context
    ): void {
        $cacheKey = $this->getCacheKey($field, $value, $rules);
        
        if ($cached = $this->cache->get($cacheKey)) {
            if (!$cached['valid']) {
                throw new ValidationException($cached['error']);
            }
            return;
        }

        foreach ($rules as $rule) {
            $validator = $this->rules->getValidator($rule);
            
            if (!$validator->validate($value, $context)) {
                $error = $validator->getError();
                $this->cache->set($cacheKey, [
                    'valid' => false,
                    'error' => $error
                ]);
                throw new ValidationException($error);
            }
        }

        $this->cache->set($cacheKey, [
            'valid' => true
        ]);
    }

    private function validateCrossFieldRules(array $data, array $rules): void
    {
        $crossFieldRules = $this->rules->getCrossFieldRules($rules);
        
        foreach ($crossFieldRules as $rule) {
            if (!$rule->validate($data)) {
                throw new ValidationException($rule->getError());
            }
        }
    }

    private function validateBusinessRules(
        array $data,
        ValidationContext $context
    ): void {
        $businessRules = $this->rules->getBusinessRules($context);
        
        foreach ($businessRules as $rule) {
            try {
                DB::beginTransaction();
                
                if (!$rule->validate($data, $context)) {
                    throw new BusinessRuleException($rule->getError());
                }
                
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }

    public function validateResult(OperationResult $result): bool
    {
        $rules = $this->rules->getResultRules($result->getType());
        
        try {
            return $this->validateInput($result->getData(), $rules)->isValid();
        } catch (ValidationException $e) {
            $this->logger->logResultValidationFailure($result, $e);
            return false;
        }
    }

    public function verifyIntegrity(array $data): bool
    {
        try {
            $hash = $data['_hash'] ?? null;
            unset($data['_hash']);
            
            if (!$hash || !$this->verifyHash($data, $hash)) {
                throw new IntegrityException('Data integrity check failed');
            }

            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    if (!$this->verifyIntegrity($value)) {
                        return false;
                    }
                }
            }

            return true;
            
        } catch (\Exception $e) {
            $this->logger->logIntegrityFailure($data, $e);
            return false;
        }
    }

    private function verifyHash(array $data, string $hash): bool
    {
        return hash_equals(
            $hash,
            hash_hmac('sha256', serialize($data), $this->getHashKey())
        );
    }

    private function getCacheKey(string $field, mixed $value, array $rules): string
    {
        return sprintf(
            'validation:%s:%s:%s',
            $field,
            md5(serialize($value)),
            md5(serialize($rules))
        );
    }

    private function getHashKey(): string
    {
        return config('app.validation_key');
    }
}
