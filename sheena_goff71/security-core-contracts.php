<?php

namespace App\Core\Security\Contracts;

interface SecurityManagerInterface
{
    public function validateAccess(SecurityContext $context): ValidationResult;
    public function executeSecureOperation(Operation $operation, SecurityContext $context): OperationResult;
    public function verifySecurityState(): SecurityState;
}

interface ValidationService
{
    public function validateInput(array $data, array $rules): ValidationResult;
    public function validateSecurityContext(SecurityContext $context): bool;
    public function verifyIntegrity(array $data): bool;
}

interface EncryptionService
{
    public function encrypt(mixed $data, array $context = []): EncryptedData;
    public function decrypt(EncryptedData $data, array $context = []): mixed;
    public function verifySignature(array $data): bool;
}

interface AuditLogger
{
    public function logSecurityEvent(string $eventType, SecurityContext $context, array $data = []): void;
    public function logFailure(string $operation, SecurityContext $context, \Exception $error): void;
    public function logUnauthorizedAccess(SecurityContext $context, string $resource = null): void;
}

interface AccessControl
{
    public function validateAccess(SecurityContext $context, array $requiredPermissions): void;
    public function hasPermission(Role $role, Permission $permission): bool;
    public function validateRoleConstraints(array $roles, SecurityContext $context): bool;
}

interface MetricsCollector
{
    public function trackMetric(string $name, $value, array $tags = []): void;
    public function startOperation(string $operation, array $context = []): string;
    public function endOperation(string $operationId, array $result = []): void;
}

namespace App\Core\Security\Models;

class SecurityContext
{
    private string $userId;
    private array $roles;
    private string $ipAddress;
    private string $sessionId;
    private array $permissions;
    private string $requestId;
    private array $securityLevel;
    private array $metadata;

    public function __construct(array $data)
    {
        $this->userId = $data['user_id'];
        $this->roles = $data['roles'];
        $this->ipAddress = $data['ip_address'];
        $this->sessionId = $data['session_id'];
        $this->permissions = $data['permissions'];
        $this->requestId = $data['request_id'];
        $this->securityLevel = $data['security_level'];
        $this->metadata = $data['metadata'] ?? [];
    }

    public function getUserId(): string { return $this->userId; }
    public function getRoles(): array { return $this->roles; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function getSessionId(): string { return $this->sessionId; }
    public function getPermissions(): array { return $this->permissions; }
    public function getRequestId(): string { return $this->requestId; }
    public function getSecurityLevel(): array { return $this->securityLevel; }
    public function getMetadata(): array { return $this->metadata; }
}

class EncryptedData
{
    private string $data;
    private string $iv;
    private string $mac;
    private string $keyId;
    private string $cipher;

    public function __construct(string $data, string $iv, string $mac, string $keyId, string $cipher)
    {
        $this->data = $data;
        $this->iv = $iv;
        $this->mac = $mac;
        $this->keyId = $keyId;
        $this->cipher = $cipher;
    }

    public function getData(): string { return $this->data; }
    public function getIv(): string { return $this->iv; }
    public function getMac(): string { return $this->mac; }
    public function getKeyId(): string { return $this->keyId; }
    public function getCipher(): string { return $this->cipher; }
}

class ValidationResult
{
    private bool $valid;
    private array $errors;
    private array $metadata;

    public function __construct(bool $valid, array $errors = [], array $metadata = [])
    {
        $this->valid = $valid;
        $this->errors = $errors;
        $this->metadata = $metadata;
    }

    public function isValid(): bool { return $this->valid; }
    public function getErrors(): array { return $this->errors; }
    public function getMetadata(): array { return $this->metadata; }
}

class SecurityEvent
{
    private string $eventType;
    private string $timestamp;
    private string $severity;
    private array $data;
    private SecurityContext $context;
    private array $metadata;

    public function __construct(
        string $eventType,
        SecurityContext $context,
        array $data = [],
        string $severity = 'info'
    ) {
        $this->eventType = $eventType;
        $this->timestamp = microtime(true);
        $this->severity = $severity;
        $this->data = $data;
        $this->context = $context;
        $this->metadata = [];
    }

    public function getEventType(): string { return $this->eventType; }
    public function getTimestamp(): string { return $this->timestamp; }
    public function getSeverity(): string { return $this->severity; }
    public function getData(): array { return $this->data; }
    public function getContext(): SecurityContext { return $this->context; }
    public function getMetadata(): array { return $this->metadata; }
}

class OperationResult
{
    private bool $success;
    private mixed $data;
    private array $errors;
    private array $metadata;

    public function __construct(bool $success, $data = null, array $errors = [], array $metadata = [])
    {
        $this->success = $success;
        $this->data = $data;
        $this->errors = $errors;
        $this->metadata = $metadata;
    }

    public function isSuccessful(): bool { return $this->success; }
    public function getData(): mixed { return $this->data; }
    public function getErrors(): array { return $this->errors; }
    public function getMetadata(): array { return $this->metadata; }
}
