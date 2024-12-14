namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private RuleEngine $ruleEngine;
    private IntegrityChecker $integrityChecker;
    private CacheManager $cache;
    private LogManager $logger;
    private ValidationConfig $config;

    public function __construct(
        RuleEngine $ruleEngine,
        IntegrityChecker $integrityChecker,
        CacheManager $cache,
        LogManager $logger,
        ValidationConfig $config
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->integrityChecker = $integrityChecker;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function validateInput(array $data, array $rules): ValidationResult 
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateCacheKey($data, $rules);

        try {
            if ($cachedResult = $this->getCachedValidation($cacheKey)) {
                return $cachedResult;
            }

            $this->validateRules($rules);
            $sanitizedData = $this->sanitizeInput($data);
            $validationResult = $this->executeValidation($sanitizedData, $rules);
            
            if (!$validationResult->isValid()) {
                throw new ValidationException(
                    $validationResult->getErrors(),
                    ValidationErrorCode::INVALID_INPUT
                );
            }

            $this->cacheValidationResult($cacheKey, $validationResult);
            $this->logValidationSuccess($data, $rules, microtime(true) - $startTime);

            return $validationResult;

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
    }

    public function verifyIntegrity($data): bool 
    {
        return $this->integrityChecker->verify($data, [
            'checksum' => true,
            'structure' => true,
            'constraints' => true
        ]);
    }

    public function verifyBusinessRules($data): bool 
    {
        return $this->ruleEngine->evaluateBusinessRules(
            $data,
            $this->config->getBusinessRules()
        );
    }

    private function validateRules(array $rules): void 
    {
        if (!$this->ruleEngine->validateRuleSet($rules)) {
            throw new InvalidRuleException('Invalid validation rules provided');
        }
    }

    private function sanitizeInput(array $data): array 
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            }
            if (is_array($value)) {
                return $this->sanitizeInput($value);
            }
            return $value;
        }, $data);
    }

    private function sanitizeString(string $value): string 
    {
        $value = trim($value);
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $value;
    }

    private function executeValidation(array $data, array $rules): ValidationResult 
    {
        $errors = [];
        $validatedData = [];

        foreach ($rules as $field => $fieldRules) {
            try {
                $validatedData[$field] = $this->validateField(
                    $data[$field] ?? null,
                    $fieldRules,
                    $field
                );
            } catch (ValidationException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        return new ValidationResult(
            empty($errors),
            $validatedData,
            $errors
        );
    }

    private function validateField($value, array $rules, string $field): mixed 
    {
        foreach ($rules as $rule => $parameters) {
            if (!$this->ruleEngine->evaluate($value, $rule, $parameters)) {
                throw new ValidationException(
                    sprintf(
                        'Field %s failed validation rule %s',
                        $field,
                        $rule
                    )
                );
            }
        }

        return $value;
    }

    private function generateCacheKey(array $data, array $rules): string 
    {
        return hash('sha256', serialize([
            'data' => $data,
            'rules' => $rules,
            'version' => $this->config->getVersion()
        ]));
    }

    private function getCachedValidation(string $key): ?ValidationResult 
    {
        if (!$this->config->isCacheEnabled()) {
            return null;
        }

        return $this->cache->get($key);
    }

    private function cacheValidationResult(
        string $key,
        ValidationResult $result
    ): void {
        if ($this->config->isCacheEnabled()) {
            $this->cache->set(
                $key,
                $result,
                $this->config->getCacheTtl()
            );
        }
    }

    private function logValidationSuccess(
        array $data,
        array $rules,
        float $duration
    ): void {
        $this->logger->info('Validation successful', [
            'data_size' => strlen(serialize($data)),
            'rules_count' => count($rules),
            'duration' => $duration,
            'cache_enabled' => $this->config->isCacheEnabled()
        ]);
    }

    private function handleValidationFailure(
        \Exception $e,
        array $data,
        array $rules
    ): void {
        $this->logger->error('Validation failed', [
            'exception' => $e->getMessage(),
            'data_size' => strlen(serialize($data)),
            'rules_count' => count($rules),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
