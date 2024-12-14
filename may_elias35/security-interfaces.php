<?php

namespace App\Core\Security;

interface SecurityManagerInterface
{
    public function validateRequest(Request $request): ValidationResult;
    public function checkPermissions(SecurityContext $context, array $permissions): bool;
    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): OperationResult;
    public function handleSecurityBreach(array $data): void;
    public function validateSecurityContext(SecurityContext $context): bool;
    public function enforceSecurityPolicies(): void;
    public function startSecurityMonitoring(): void;
}

interface SecurityContextInterface
{
    public function getUserId(): ?int;
    public function getIpAddress(): string;
    public function getUserAgent(): string;
    public function getSessionId(): string;
    public function getPermissions(): array;
    public function getRoles(): array;
    public function isValid(): bool;
    public function getContext(): array;
}

interface CriticalOperationInterface
{
    public function execute(): OperationResult;
    public function validate(): bool;
    public function getType(): string;
    public function getRiskLevel(): int;
    public function getRequiredPermissions(): array;
    public function getId(): string;
}

interface ValidationServiceInterface
{
    public function validateInput(array $data): bool;
    public function validateOutput(array $data): bool;
    public function validateOperation(string $operation): bool;
    public function validateContext(array $context): bool;
    public function validateResult(OperationResult $result): bool;
}

interface ProtectionManagerInterface
{
    public function startProtection(CriticalOperation $operation): void;
    public function endProtection(CriticalOperation $operation): void;
    public function handleFailure(CriticalOperation $operation, \Exception $e): void;
    public function checkRateLimit(SecurityContext $context): bool;
    public function enforceProtection(array $policies): void;
}

interface AuditLoggerInterface
{
    public function logSecurityEvent(string $event, array $data, SecurityContext $context): void;
    public function logCriticalEvent(string $event, array $data): void;
    public function logAuditEvent(string $event, array $data): void;
    public function logSystemEvent(string $event, array $data): void;
}
