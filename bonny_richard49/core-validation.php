<?php

namespace App\Core\Services;

use App\Core\Contracts\{ValidationInterface, SecurityInterface};
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\Validator;
use App\Core\Exceptions\{ValidationException, SecurityException};

class ValidationService implements ValidationInterface
{
    protected SecurityManager $security;
    protected array $defaultRules;
    protected array $customValidators;

    public function __construct(
        SecurityManager $security,
        array $defaultRules = [],
        array $customValidators = []
    ) {
        $this->security = $security;
        $this->defaultRules = $defaultRules;
        $this->customValidators = $customValidators;
        $this->registerCustomValidators();
    }

    public function validate(array $data, array $rules = []): array
    {
        $validator = Validator::make(
            $data,
            array_merge($this->defaultRules, $rules),
            $this->getCustomMessages()
        );

        if ($validator->fails()) {
            throw new ValidationException(
                'Validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    public function validateWithContext(array $data, array $rules, array $context): array
    {
        return $this->security->executeSecureOperation(
            fn() => $this->validate($data, $this->mergeContextRules($rules, $context)),
            ['action' => 'validate', 'context' => $context]
        );
    }

    protected function mergeContextRules(array $rules, array $context): array
    {
        $contextRules = [];

        foreach ($this->getContextualRules() as $key => $rule) {
            if (isset($context[$key])) {
                $contextRules[$key] = $rule;
            }
        }

        return array_merge($rules, $contextRules);
    }

    protected function registerCustomValidators(): void
    {
        foreach ($this->customValidators as $name => $validator) {
            Validator::extend($name, $validator);
        }
    }

    protected function getCustomMessages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'string' => 'The :attribute must be a string.',
            'integer' => 'The :attribute must be an integer.',
            'array' => 'The :attribute must be an array.',
            'exists' => 'The selected :attribute is invalid.',
            'unique' => 'The :attribute has already been taken.',
        ];
    }

    protected function getContextualRules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permissions' => ['array', 'exists:permissions,id'],
        ];
    }
}

class SecurityService implements SecurityInterface
{
    protected array $config;
    protected array $securityChecks;

    public function __construct(array $config = [], array $securityChecks = [])
    {
        $this->config = $config;
        $this->securityChecks = $securityChecks;
    }

    public function validateSecurityContext(array $context): void
    {
        foreach ($this->securityChecks as $check) {
            if (!$check->validate($context)) {
                throw new SecurityException('Security validation failed');
            }
        }
    }

    public function verifyPermissions(array $required, array $provided): bool
    {
        foreach ($required as $permission) {
            if (!in_array($permission, $provided)) {
                return false;
            }
        }
        return true;
    }

    public function validateDataIntegrity(array $data, string $hash): bool
    {
        return hash_equals(
            $hash,
            hash_hmac('sha256', json_encode($data), $this->config['app_key'])
        );
    }

    public function encryptSensitiveData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->shouldEncrypt($key) 
                ? $this->encrypt($value) 
                : $value;
        }
        return $result;
    }

    public function decryptSensitiveData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->shouldEncrypt($key) 
                ? $this->decrypt($value) 
                : $value;
        }
        return $result;
    }

    protected function shouldEncrypt(string $key): bool
    {
        return in_array($key, $this->config['sensitive_fields'] ?? []);
    }

    protected function encrypt(string $value): string
    {
        return openssl_encrypt(
            $value,
            $this->config['cipher'],
            $this->config['app_key'],
            0,
            $this->config['iv']
        );
    }

    protected function decrypt(string $encrypted): string
    {
        return openssl_decrypt(
            $encrypted,
            $this->config['cipher'],
            $this->config['app_key'],
            0,
            $this->config['iv']
        );
    }
}

class AuditService
{
    protected string $logPath;
    protected array $config;

    public function __construct(string $logPath, array $config = [])
    {
        $this->logPath = $logPath;
        $this->config = $config;
    }

    public function logSecurityEvent(string $event, array $context = []): void
    {
        $this->logEvent('security', $event, $context);
    }

    public function logOperationEvent(string $event, array $context = []): void
    {
        $this->logEvent('operation', $event, $context);
    }

    public function logValidationEvent(string $event, array $context = []): void
    {
        $this->logEvent('validation', $event, $context);
    }

    protected function logEvent(string $type, string $event, array $context): void
    {
        $logEntry = [
            'timestamp' => now()->toIso8601String(),
            'type' => $type,
            'event' => $event,
            'context' => $context,
            'session_id' => session()->getId(),
            'request_id' => request()->id(),
        ];

        file_put_contents(
            $this->getLogPath($type),
            json_encode($logEntry) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    protected function getLogPath(string $type): string
    {
        return sprintf(
            '%s/%s_%s.log',
            $this->logPath,
            $type,
            now()->format('Y-m-d')
        );
    }
}