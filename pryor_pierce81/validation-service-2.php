<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Security\Context\ValidationContext;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationServiceInterface
{
    private array $securityConfig;
    private array $validationRules;
    private RateLimiter $rateLimiter;
    private AuthenticationService $authService;
    private AuthorizationService $authzService;
    
    public function __construct(
        array $securityConfig,
        array $validationRules,
        RateLimiter $rateLimiter,
        AuthenticationService $authService,
        AuthorizationService $authzService
    ) {
        $this->securityConfig = $securityConfig;
        $this->validationRules = $validationRules;
        $this->rateLimiter = $rateLimiter;
        $this->authService = $authService;
        $this->authzService = $authzService;
    }

    public function validateAuthentication(string $token): bool
    {
        if (!$this->authService->verifyToken($token)) {
            $this->logFailedAuthentication($token);
            return false;
        }

        $tokenData = $this->authService->decodeToken($token);
        if ($this->isTokenExpired($tokenData)) {
            $this->logExpiredToken($tokenData);
            return false;
        }

        return $this->verifyTokenSignature($token);
    }

    public function validateAuthorization(int $userId, array $requiredPermissions): bool
    {
        $userPermissions = $this->authzService->getUserPermissions($userId);
        $hasPermissions = $this->authzService->checkPermissions($userPermissions, $requiredPermissions);
        
        if (!$hasPermissions) {
            $this->logUnauthorizedAccess($userId, $requiredPermissions);
            return false;
        }

        return true;
    }

    public function validateRateLimit(string $operationKey): bool
    {
        $limit = $this->securityConfig['rate_limits'][$operationKey] ?? 
                $this->securityConfig['rate_limits']['default'];

        if (!$this->rateLimiter->check($operationKey, $limit)) {
            $this->logRateLimitExceeded($operationKey);
            return false;
        }

        return true;
    }

    public function validateData(array $data): bool
    {
        foreach ($data as $field => $value) {
            if (!$this->validateField($field, $value)) {
                $this->logValidationFailure($field, $value);
                return false;
            }
        }

        return $this->validateDataIntegrity($data);
    }

    public function validateOutput($result): bool
    {
        if (!$this->isValidStructure($result)) {
            $this->logInvalidOutput($result);
            return false;
        }

        return $this->validateOutputSecurity($result);
    }

    protected function validateField(string $field, $value): bool
    {
        $rules = $this->validationRules[$field] ?? [];
        
        foreach ($rules as $rule => $parameter) {
            if (!$this->applyValidationRule($value, $rule, $parameter)) {
                return false;
            }
        }

        return true;
    }

    protected function applyValidationRule($value, string $rule, $parameter): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'min' => is_numeric($value) && $value >= $parameter,
            'max' => is_numeric($value) && $value <= $parameter,
            'length' => is_string($value) && strlen($value) <= $parameter,
            'pattern' => is_string($value) && preg_match($parameter, $value),
            'type' => $this->validateType($value, $parameter),
            'enum' => in_array($value, $parameter, true),
            default => $this->applyCustomRule($value, $rule, $parameter)
        };
    }

    protected function validateType($value, string $type): bool
    {
        return match($type) {
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'boolean' => is_bool($value),
            default => false
        };
    }

    protected function validateDataIntegrity(array $data): bool
    {
        $hash = hash_hmac(
            'sha256',
            json_encode($data),
            $this->securityConfig['integrity_key']
        );

        return hash_equals(
            $data['_integrity'] ?? '',
            $hash
        );
    }

    protected function validateOutputSecurity($result): bool
    {
        if (is_array($result)) {
            return $this->validateArrayOutput($result);
        }

        if (is_object($result)) {
            return $this->validateObjectOutput($result);
        }

        return $this->validateScalarOutput($result);
    }

    protected function isValidStructure($result): bool
    {
        return !is_resource($result) && 
               !is_callable($result) && 
               ($result === null || is_scalar($result) || is_array($result) || is_object($result));
    }

    protected function logValidationFailure(string $field, $value): void
    {
        Log::warning('Validation failure', [
            'field' => $field,
            'value' => $value,
            'timestamp' => time()
        ]);
    }

    protected function verifyTokenSignature(string $token): bool
    {
        return $this->authService->verifySignature($token);
    }

    protected function isTokenExpired(array $tokenData): bool
    {
        return $tokenData['exp'] < time();
    }
}
