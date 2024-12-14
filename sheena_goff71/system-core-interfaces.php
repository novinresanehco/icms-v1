<?php

namespace App\Core\Contracts;

interface SecurityManagerInterface
{
    public function validateSecureOperation(callable $operation, SecurityContext $context): mixed;
    public function verifyAccess(string $resource, string $action, SecurityContext $context): bool;
    public function enforceSecurityPolicy(string $policy, array $parameters = []): void;
    public function generateSecurityAudit(string $scope): AuditResult;
}

interface ContentManagerInterface
{
    public function createContent(array $data, SecurityContext $context): ContentResult;
    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult;
    public function deleteContent(int $id, SecurityContext $context): bool;
    public function validateContent(array $data): ValidationResult;
}

interface MonitoringInterface
{
    public function startOperation(string $type): string;
    public function stopOperation(string $operationId): void;
    public function recordMetric(string $name, $value, array $tags = []): void;
    public function triggerAlert(string $type, array $data, string $severity = 'warning'): void;
}

interface ValidationInterface
{
    public function validateData(array $data): array;
    public function verifyIntegrity($data): bool;
    public function validateConstraints(array $data, array $constraints): bool;
    public function generateValidationReport(): ValidationReport;
}

interface CacheInterface
{
    public function get(string $key, $default = null): mixed;
    public function set(string $key, $value, int $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
}

interface DataManagerInterface
{
    public function store(array $data, SecurityContext $context): DataResult;
    public function retrieve(int $id, SecurityContext $context): DataResult;
    public function update(int $id, array $data, SecurityContext $context): DataResult;
    public function delete(int $id, SecurityContext $context): bool;
}

interface AuditInterface
{
    public function logAccess(AccessContext $context): void;
    public function logOperation(OperationContext $context): void;
    public function logSecurity(SecurityEvent $event): void;
    public function generateAuditReport(array $filters = []): AuditReport;
}

interface BackupInterface
{
    public function createBackup(string $type): string;
    public function restoreFromBackup(string $backupId): bool;
    public function verifyBackup(string $backupId): bool;
    public function listBackups(array $filters = []): array;
}

interface EncryptionInterface
{
    public function encrypt(string $data, array $context = []): string;
    public function decrypt(string $encrypted, array $context = []): string;
    public function generateKey(string $type = 'default'): string;
    public function rotateKeys(array $parameters = []): void;
}

interface PerformanceInterface
{
    public function measureOperation(callable $operation): PerformanceMetrics;
    public function optimizeResource(string $resource): OptimizationResult;
    public function analyzePerformance(string $context): PerformanceReport;
    public function enforceThresholds(array $thresholds): void;
}

interface IntegrationInterface
{
    public function registerService(string $service, array $config): ServiceRegistration;
    public function validateIntegration(string $serviceId): ValidationResult;
    public function executeIntegration(string $serviceId, array $data): IntegrationResult;
    public function monitorIntegration(string $serviceId): MonitoringResult;
}

interface ErrorHandlerInterface
{
    public function handleError(\Throwable $error, array $context = []): void;
    public function registerErrorHandler(string $type, callable $handler): void;
    public function getErrorReport(string $errorId): ErrorReport;
    public function analyzeErrorPatterns(): ErrorAnalysis;
}

interface SystemStateInterface
{
    public function captureState(string $context): SystemState;
    public function restoreState(SystemState $state): bool;
    public function validateState(SystemState $state): ValidationResult;
    public function compareStates(SystemState $state1, SystemState $state2): ComparisonResult;
}

interface ConfigurationInterface
{
    public function loadConfig(string $name): array;
    public function validateConfig(array $config, array $schema): bool;
    public function updateConfig(string $name, array $values): bool;
    public function backupConfig(string $name): string;
}

interface MaintenanceInterface
{
    public function scheduleMaintenance(string $type, array $parameters): MaintenanceSchedule;
    public function executeMaintenance(string $maintenanceId): MaintenanceResult;
    public function verifyMaintenance(string $maintenanceId): VerificationResult;
    public function generateMaintenanceReport(): MaintenanceReport;
}

interface ResourceManagerInterface
{
    public function allocateResource(string $type, array $requirements): ResourceAllocation;
    public function releaseResource(string $resourceId): bool;
    public function monitorResource(string $resourceId): ResourceMetrics;
    public function optimizeResources(array $parameters = []): OptimizationResult;
}

interface LogManagerInterface
{
    public function logEvent(string $type, array $data): void;
    public function queryLogs(array $filters): array;
    public function rotateLogs(string $type): bool;
    public function archiveLogs(string $beforeDate): bool;
}

interface QueueManagerInterface
{
    public function pushJob(string $type, array $data): string;
    public function processQueue(string $queueName): void;
    public function monitorQueue(string $queueName): QueueMetrics;
    public function purgeQueue(string $queueName): bool;
}

interface NotificationInterface
{
    public function sendNotification(string $type, array $data, array $recipients): void;
    public function scheduleNotification(string $type, array $data, \DateTime $datetime): string;
    public function cancelNotification(string $notificationId): bool;
    public function getNotificationStatus(string $notificationId): NotificationStatus;
}
