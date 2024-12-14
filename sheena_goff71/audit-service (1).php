<?php

namespace App\Core\Security\Services;

use App\Core\Interfaces\AuditInterface;
use App\Core\Security\Models\{AuditLog, SecurityContext};
use App\Core\Events\AuditEvent;
use Illuminate\Support\Facades\{DB, Event, Cache};
use Psr\Log\LoggerInterface;

class AuditService implements AuditInterface
{
    private LoggerInterface $logger;
    private string $appEnvironment;
    private array $sensitiveFields = ['password', 'token', 'secret'];

    public function __construct(
        LoggerInterface $logger,
        string $appEnvironment
    ) {
        $this->logger = $logger;
        $this->appEnvironment = $appEnvironment;
    }

    public function logValidation(SecurityContext $context): void
    {
        DB::beginTransaction();
        try {
            $logEntry = new AuditLog([
                'type' => 'validation',
                'user_id' => $context->getUserId(),
                'action' => $context->getAction(),
                'resource' => $context->getResource(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'timestamp' => now(),
                'environment' => $this->appEnvironment,
                'status' => 'success',
                'metadata' => $this->sanitizeMetadata($context->getMetadata())
            ]);

            $logEntry->save();
            
            Event::dispatch(new AuditEvent('validation', $logEntry));
            
            $this->updateSecurityMetrics('validation_success');
            
            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleLoggingFailure('validation_logging_failed', $e);
        }
    }

    public function logOperation(SecurityContext $context, array $details): void
    {
        DB::beginTransaction();
        try {
            $logEntry = new AuditLog([
                'type' => 'operation',
                'user_id' => $context->getUserId(),
                'action' => $context->getAction(),
                'resource' => $context->getResource(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'timestamp' => now(),
                'environment' => $this->appEnvironment,
                'status' => 'success',
                'details' => $this->sanitizeDetails($details),
                'duration' => $details['duration'] ?? null,
                'metadata' => $this->sanitizeMetadata($context->getMetadata())
            ]);

            $logEntry->save();
            
            Event::dispatch(new AuditEvent('operation', $logEntry));
            
            $this->updateSecurityMetrics('operation_success');
            
            // Cache frequently accessed audit data
            $this->cacheAuditData($logEntry);
            
            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleLoggingFailure('operation_logging_failed', $e);
        }
    }

    public function logFailure(string $type, \Throwable $error, SecurityContext $context): void
    {
        DB::beginTransaction();
        try {
            $logEntry = new AuditLog([
                'type' => 'failure',
                'user_id' => $context->getUserId(),
                'action' => $context->getAction(),
                'resource' => $context->getResource(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'timestamp' => now(),
                'environment' => $this->appEnvironment,
                'status' => 'failure',
                'error_type' => $type,
                'error_message' => $error->getMessage(),
                'error_trace' => $error->getTraceAsString(),
                'metadata' => $this->sanitizeMetadata($context->getMetadata())
            ]);

            $logEntry->save();
            
            Event::dispatch(new AuditEvent('failure', $logEntry));
            
            $this->updateSecurityMetrics('operation_failure');
            
            // Alert on critical failures
            $this->alertOnCriticalFailure($logEntry);
            
            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleLoggingFailure('failure_logging_failed', $e);
        }
    }

    private function sanitizeMetadata(array $metadata): array
    {
        return array_filter($metadata, function($key) {
            return !in_array($key, $this->sensitiveFields);
        }, ARRAY_FILTER_USE_KEY);
    }

    private function sanitizeDetails(array $details): array
    {
        $sanitized = [];
        foreach ($details as $key => $value) {
            if (!in_array($key, $this->sensitiveFields)) {
                $sanitized[$key] = is_array($value) ? 
                    $this->sanitizeDetails($value) : $value;
            }
        }
        return $sanitized;
    }

    private function updateSecurityMetrics(string $metric): void
    {
        $key = "security_metrics:{$metric}:" . date('Y-m-d');
        Cache::increment($key);
    }

    private function cacheAuditData(AuditLog $log): void
    {
        $cacheKey = "audit_log:{$log->id}";
        Cache::put($cacheKey, $log, now()->addHours(24));
    }

    private function alertOnCriticalFailure(AuditLog $log): void
    {
        if ($this->isCriticalFailure($log)) {
            // Implement critical failure alerting
        }
    }

    private function isCriticalFailure(AuditLog $log): bool
    {
        return in_array($log->error_type, [
            'security_breach',
            'data_corruption',
            'system_compromise'
        ]);
    }

    private function handleLoggingFailure(string $type, \Throwable $e): void
    {
        $this->logger->critical("Audit logging failure: {$type}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
