<?php

namespace App\Core\Audit;

use App\Core\Security\{SecurityContext, CoreSecurityManager};
use Illuminate\Support\Facades\{DB, Log};
use Monolog\Logger;
use Monolog\Handler\{StreamHandler, RotatingFileHandler};

class AuditLogger implements AuditInterface
{
    private CoreSecurityManager $security;
    private Logger $logger;
    private AuditRepository $repository;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        CoreSecurityManager $security,
        AuditRepository $repository,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->metrics = $metrics;
        $this->config = $config;
        $this->initializeLogger();
    }

    public function logSecurityEvent(string $eventType, array $data, SecurityContext $context): void
    {
        $this->logCriticalEvent('security', $eventType, $data, $context);
    }

    public function logSystemEvent(string $eventType, array $data, SecurityContext $context): void
    {
        $this->logEvent('system', $eventType, $data, $context);
    }

    public function logUserActivity(string $activity, array $data, SecurityContext $context): void
    {
        $this->logEvent('user', $activity, $data, $context);
    }

    public function logPerformanceMetrics(array $metrics, SecurityContext $context): void
    {
        $this->logEvent('performance', 'metrics', $metrics, $context);
        $this->metrics->recordPerformanceMetrics($metrics);
    }

    public function logErrorEvent(string $eventType, \Throwable $error, array $context): void
    {
        $this->logCriticalEvent('error', $eventType, [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'context' => $context
        ], new SecurityContext(['system' => true]));
    }

    public function searchAuditLogs(array $criteria, SecurityContext $context): AuditCollection
    {
        return $this->security->executeCriticalOperation(
            new AuditOperation('search', $criteria),
            $context,
            fn() => $this->repository->search($criteria)
        );
    }

    public function getAuditReport(array $criteria, SecurityContext $context): AuditReport
    {
        return $this->security->executeCriticalOperation(
            new AuditOperation('report', $criteria),
            $context,
            function() use ($criteria) {
                $logs = $this->repository->getReportData($criteria);
                return new AuditReport($logs, $criteria);
            }
        );
    }

    private function logCriticalEvent(string $category, string $eventType, array $data, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new AuditOperation('log', [
                'category' => $category,
                'event_type' => $eventType,
                'data' => $data
            ]),
            $context,
            function() use ($category, $eventType, $data, $context) {
                DB::beginTransaction();
                try {
                    $entry = $this->createAuditEntry($category, $eventType, $data, $context);
                    $this->repository->create($entry);
                    
                    if ($this->isHighSeverity($category, $eventType)) {
                        $this->notifySecurityTeam($entry);
                    }
                    
                    $this->logger->critical($eventType, $entry);
                    DB::commit();
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::emergency('Failed to log critical event', [
                        'error' => $e->getMessage(),
                        'event' => $eventType,
                        'data' => $data
                    ]);
                    throw $e;
                }
            }
        );
    }

    private function logEvent(string $category, string $eventType, array $data, SecurityContext $context): void
    {
        try {
            $entry = $this->createAuditEntry($category, $eventType, $data, $context);
            $this->repository->create($entry);
            $this->logger->info($eventType, $entry);
            
        } catch (\Exception $e) {
            Log::error('Failed to log event', [
                'error' => $e->getMessage(),
                'event' => $eventType,
                'data' => $data
            ]);
        }
    }

    private function createAuditEntry(string $category, string $eventType, array $data, SecurityContext $context): array
    {
        return [
            'category' => $category,
            'event_type' => $eventType,
            'data' => $this->sanitizeData($data),
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'timestamp' => now(),
            'session_id' => $context->getSessionId(),
            'request_id' => $context->getRequestId()
        ];
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $this->config['sensitive_fields'])) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = is_array($value) ? 
                    $this->sanitizeData($value) : 
                    $this->sanitizeValue($value);
            }
        }
        return $sanitized;
    }

    private function sanitizeValue($value): mixed
    {
        if (is_string($value) && strlen($value) > $this->config['max_field_length']) {
            return substr($value, 0, $this->config['max_field_length']) . '...';
        }
        return $value;
    }

    private function isHighSeverity(string $category, string $eventType): bool
    {
        return in_array("{$category}.{$eventType}", $this->config['high_severity_events']);
    }

    private function notifySecurityTeam(array $entry): void
    {
        foreach ($this->config['security_notifications'] as $notification) {
            try {
                $notification->send($entry);
            } catch (\Exception $e) {
                Log::error('Failed to send security notification', [
                    'error' => $e->getMessage(),
                    'entry' => $entry
                ]);
            }
        }
    }

    private function initializeLogger(): void
    {
        $this->logger = new Logger('audit');
        
        $this->logger->pushHandler(
            new RotatingFileHandler(
                storage_path('logs/audit.log'),
                $this->config['log_rotation_days'],
                Logger::INFO
            )
        );

        if ($this->config['log_to_stdout']) {
            $this->logger->pushHandler(
                new StreamHandler('php://stdout', Logger::INFO)
            );
        }
    }
}
