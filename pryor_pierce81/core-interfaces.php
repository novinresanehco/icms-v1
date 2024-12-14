<?php

namespace App\Core\Contracts;

interface SecurityManagerInterface
{
    public function validateSecureOperation(string $operation, array $context): bool;
    public function enforceSecurityPolicy(string $policy, array $params): void;
    public function validateSystemSecurity(): array;
    public function handleSecurityBreach(array $context): void;
    public function isOperationAllowed(string $operation, array $context): bool;
}

interface ValidationManagerInterface
{
    public function validateOperation(string $operation, array $context): bool;
    public function validateData(array $data, array $rules): array;
    public function validateRequest(Request $request): bool;
    public function validateResponse(Response $response): bool;
    public function getValidationErrors(): array;
}

interface AuditManagerInterface
{
    public function logCriticalOperation(string $operation, array $context): void;
    public function logSecurityEvent(SecurityEvent $event): void;
    public function logValidationFailure(string $operation, array $errors): void;
    public function getAuditTrail(array $criteria): array;
    public function validateAuditIntegrity(): bool;
}

interface DatabaseManagerInterface
{
    public function executeQuery(string $query, array $params = []): QueryResult;
    public function beginTransaction(): string;
    public function commitTransaction(string $transactionId): void;
    public function rollbackTransaction(string $transactionId): void;
    public function validateDatabaseState(): bool;
}

interface CacheManagerInterface
{
    public function store(string $key, $data, int $ttl = null): bool;
    public function retrieve(string $key);
    public function invalidate(string $key): bool;
    public function validateCacheIntegrity(): bool;
    public function clearCache(): void;
}

interface EventManagerInterface
{
    public function dispatchEvent(Event $event): void;
    public function registerHandler(string $eventType, EventHandler $handler): void;
    public function validateEventHandlers(string $eventType): bool;
    public function getRegisteredHandlers(): array;
}

interface LoggingManagerInterface
{
    public function logSystemEvent(SystemEvent $event): void;
    public function logPerformanceMetrics(array $metrics): void;
    public function getSystemLogs(array $criteria): array;
    public function validateLogIntegrity(): bool;
    public function rotateLogs(): void;
}

interface BackupManagerInterface
{
    public function createBackup(string $type, array $options = []): string;
    public function restoreBackup(string $backupId): bool;
    public function validateBackup(string $backupId): bool;
    public function getBackupStatus(string $backupId): array;
    public function listBackups(): array;
}

interface MonitoringManagerInterface
{
    public function startMonitoring(string $context): string;
    public function stopMonitoring(string $monitorId): void;
    public function collectMetrics(string $monitorId): array;
    public function validateSystemHealth(): array;
    public function getAlerts(): array;
}

interface ContentManagerInterface
{
    public function createContent(array $data, array $context): Content;
    public function updateContent(int $id, array $data, array $context): Content;
    public function deleteContent(int $id, array $context): bool;
    public function validateContent(array $data): bool;
    public function publishContent(int $id, array $context): void;
}

interface UserManagerInterface
{
    public function createUser(array $data): User;
    public function updateUser(int $id, array $data): User;
    public function deleteUser(int $id): void;
    public function validateUserData(array $data): bool;
    public function validateUserOperation(int $userId, string $operation): bool;
}

interface PermissionManagerInterface
{
    public function validatePermission(string $permission, array $context): bool;
    public function assignPermission(string $permission, int $roleId): void;
    public function revokePermission(string $permission, int $roleId): void;
    public function listPermissions(int $roleId): array;
    public function validatePermissionAssignment(string $permission, int $roleId): bool;
}

interface NotificationManagerInterface
{
    public function sendNotification(Notification $notification): void;
    public function registerProvider(NotificationProvider $provider): void;
    public function validateNotification(Notification $notification): bool;
    public function getNotificationStatus(string $notificationId): array;
}

interface QueueManagerInterface
{
    public function pushJob(Job $job, string $queue = null): string;
    public function processQueue(string $queue): void;
    public function getQueueStatus(string $queue): array;
    public function validateJob(Job $job): bool;
    public function retryFailedJobs(): void;
}

interface MediaManagerInterface
{
    public function processMedia(UploadedFile $file, array $options = []): MediaFile;
    public function retrieveMedia(string $mediaId): MediaFile;
    public function deleteMedia(string $mediaId): void;
    public function validateMedia(UploadedFile $file): bool;
    public function optimizeMedia(string $mediaId, array $options): bool;
}

interface TemplateManagerInterface
{
    public function render(string $template, array $data = []): string;
    public function compile(string $template): CompiledTemplate;
    public function validateTemplate(string $template): bool;
    public function clearCompiledTemplates(): void;
    public function getTemplateMetadata(string $template): array;
}
