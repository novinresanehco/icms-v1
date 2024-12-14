<?php

namespace App\Core\Audit;

use App\Core\Interfaces\{AuditLoggerInterface, ValidationServiceInterface};
use App\Core\Exceptions\AuditException;
use Illuminate\Support\Facades\{DB, Log};
use Monolog\Logger;
use Monolog\Handler\{StreamHandler, RotatingFileHandler};
use Monolog\Formatter\JsonFormatter;

class AuditLogger implements AuditLoggerInterface 
{
    private ValidationServiceInterface $validator;
    private Logger $securityLogger;
    private Logger $operationLogger;
    private array $config;

    public function __construct(
        ValidationServiceInterface $validator,
        array $config = []
    ) {
        $this->validator = $validator;
        $this->config = $config;
        
        $this->initializeLoggers();
    }

    public function logSecurity(array $event): void
    {
        try {
            DB::beginTransaction();

            // Validate event data
            $this->validateSecurityEvent($event);

            // Persist to database
            $this->persistSecurityEvent($event);

            // Log to security log file
            $this->securityLogger->warning('Security Event', $this->sanitizeEvent($event));

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('security', $e, $event);
        }
    }

    public function logOperation(array $operation): void
    {
        try {
            DB::beginTransaction();

            // Validate operation data
            $this->validateOperation($operation);

            // Persist to database
            $this->persistOperation($operation);

            // Log to operation log file
            $this->operationLogger->info('Operation', $this->sanitizeEvent($operation));

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('operation', $e, $operation);
        }
    }

    public function logAccess(array $context): void
    {
        try {
            DB::beginTransaction();

            // Validate access context
            $this->validateAccess($context);

            // Persist to database
            $this->persistAccess($context);

            // Log to security file
            $this->securityLogger->info('Access', $this->sanitizeEvent($context));

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('access', $e, $context);
        }
    }

    public function logFailure(string $type, \Exception $e, array $context = []): void
    {
        try {
            DB::beginTransaction();

            $event = [
                'type' => $type,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'context' => $context,
                'timestamp' => now()
            ];

            // Persist to database
            $this->persistFailure($event);

            // Log to both loggers
            $this->securityLogger->error("Failure: $type", $this->sanitizeEvent($event));
            $this->operationLogger->error("Failure: $type", $this->sanitizeEvent($event));

            DB::commit();

        } catch (\Exception $error) {
            DB::rollBack();
            // Last resort logging
            Log::error('Failed to log failure', [
                'original_error' => $e->getMessage(),
                'logging_error' => $error->getMessage()
            ]);
        }
    }

    protected function initializeLoggers(): void
    {
        // Setup security logger
        $this->securityLogger = new Logger('security');
        $this->securityLogger->pushHandler(
            new RotatingFileHandler(
                storage_path('logs/security.log'),
                30,
                Logger::WARNING
            )
        );

        // Setup operation logger
        $this->operationLogger = new Logger('operation');
        $this->operationLogger->pushHandler(
            new RotatingFileHandler(
                storage_path('logs/operation.log'),
                30,
                Logger::INFO
            )
        );

        // Add JSON formatter
        $formatter = new JsonFormatter();
        foreach ($this->securityLogger->getHandlers() as $handler) {
            $handler->setFormatter($formatter);
        }
        foreach ($this->operationLogger->getHandlers() as $handler) {
            $handler->setFormatter($formatter);
        }
    }

    protected function validateSecurityEvent(array $event): void
    {
        $rules = [
            'type' => 'required|string',
            'severity' => 'required|string',
            'description' => 'required|string',
            'source' => 'required|array',
            'timestamp' => 'required|date'
        ];

        if (!$this->validator->validateInput($event)) {
            throw new AuditException('Invalid security event data');
        }
    }

    protected function validateOperation(array $operation): void
    {
        $rules = [
            'type' => 'required|string',
            'user_id' => 'required|integer',
            'action' => 'required|string',
            'resource' => 'required|array',
            'timestamp' => 'required|date'
        ];

        if (!$this->validator->validateInput($operation)) {
            throw new AuditException('Invalid operation data');
        }
    }

    protected function validateAccess(array $context): void
    {
        $rules = [
            'user_id' => 'required|integer',
            'resource' => 'required|string',
            'action' => 'required|string',
            'ip_address' => 'required|ip',
            'timestamp' => 'required|date'
        ];

        if (!$this->validator->validateInput($context)) {
            throw new AuditException('Invalid access context');
        }
    }

    protected function persistSecurityEvent(array $event): void
    {
        DB::table('security_events')->insert([
            'type' => $event['type'],
            'severity' => $event['severity'],
            'description' => $event['description'],
            'source' => json_encode($event['source']),
            'metadata' => json_encode($event['metadata'] ?? []),
            'created_at' => $event['timestamp']
        ]);
    }

    protected function persistOperation(array $operation): void
    {
        DB::table('operation_logs')->insert([
            'user_id' => $operation['user_id'],
            'type' => $operation['type'],
            'action' => $operation['action'],
            'resource' => json_encode($operation['resource']),
            'metadata' => json_encode($operation['metadata'] ?? []),
            'created_at' => $operation['timestamp']
        ]);
    }

    protected function persistAccess(array $context): void
    {
        DB::table('access_logs')->insert([
            'user_id' => $context['user_id'],
            'resource' => $context['resource'],
            'action' => $context['action'],
            'ip_address' => $context['ip_address'],
            'metadata' => json_encode($context['metadata'] ?? []),
            'created_at' => $context['timestamp']
        ]);
    }

    protected function persistFailure(array $event): void
    {
        DB::table('failure_logs')->insert([
            'type' => $event['type'],
            'error' => $event['error'],
            'code' => $event['code'],
            'file' => $event['file'],
            'line' => $event['line'],
            'trace' => $event['trace'],
            'context' => json_encode($event['context']),
            'created_at' => $event['timestamp']
        ]);
    }

    protected function sanitizeEvent(array $event): array
    {
        // Remove sensitive data
        $sanitized = $event;
        unset($sanitized['password']);
        unset($sanitized['token']);
        unset($sanitized['secret']);
        
        // Mask sensitive fields
        if (isset($sanitized['email'])) {
            $sanitized['email'] = $this->maskEmail($sanitized['email']);
        }
        
        return $sanitized;
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***@***.***';
        
        $name = $parts[0];
        $domain = $parts[1];
        
        $maskedName = substr($name, 0, 2) . str_repeat('*', strlen($name) - 2);
        return $maskedName . '@' . $domain;
    }

    protected function handleError(string $type, \Exception $e, array $context): void
    {
        // Log to system log as last resort
        Log::error("Failed to log $type event", [
            'error' => $e->getMessage(),
            'context' => $this->sanitizeEvent($context),
            'trace' => $e->getTraceAsString()
        ]);
        
        throw new AuditException(
            "Failed to log $type event: " . $e->getMessage(),
            $e->getCode(),
            $e
        );
    }
}
