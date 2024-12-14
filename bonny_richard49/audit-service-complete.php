<?php

namespace App\Core\Security;

use App\Core\Interfaces\AuditInterface;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class AuditService implements AuditInterface
{
    private LoggerInterface $logger;
    private array $config;

    private const CRITICAL_EVENTS = [
        'authentication_failure',
        'unauthorized_access', 
        'security_breach',
        'data_corruption',
        'system_error'
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = config('audit');
    }

    public function logSecurityCheck(SecurityContext $context): void
    {
        $this->createAuditLog('security_check', [
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'request_data' => $context->getRequest(),
            'timestamp' => time(),
            'result' => 'success'
        ]);
    }

    public function logTokenCreation(SecurityContext $context): void
    {
        $this->createAuditLog('token_creation', [
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => time(),
            'token_type' => $context->getTokenType()
        ]);
    }

    public function logTokenValidation(string $token, SecurityContext $context): void
    {
        $this->createAuditLog('token_validation', [
            'token_hash' => hash('sha256', $token),
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => time(),
            'result' => 'success'
        ]);
    }

    public function logSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        $this->createAuditLog('security_failure', [
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ], true);
    }

    public function logSystemAccess(SecurityContext $context): void
    {
        $this->createAuditLog('system_access', [
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'request_uri' => $context->getRequest()['uri'],
            'request_method' => $context->getRequest()['method'],
            'timestamp' => time()
        ]);
    }

    public function logDataAccess(string $operation, array $data, SecurityContext $context): void
    {
        $this->createAuditLog('data_access', [
            'operation' => $operation,
            'data_id' => $data['id'] ?? null,
            'data_type' => $data['type'] ?? null,
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => time()
        ]);
    }

    public function logSystemChange(string $operation, array $details, SecurityContext $context): void
    {
        $this->createAuditLog('system_change', [
            'operation' => $operation,
            'details' => $details,
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => time()
        ]);
    }

    protected function createAuditLog(string $type, array $data, bool $isCritical = false): void
    {
        try {
            DB::beginTransaction();

            // Create main audit log
            $logId = DB::table('audit_logs')->insertGetId([
                'type' => $type,
                'data' => json_encode($data),
                'is_critical' => $isCritical,
                'created_at' => time()
            ]);

            // Create detailed log if critical
            if ($isCritical || in_array($type, self::CRITICAL_EVENTS)) {
                $this->createCriticalAuditLog($logId, $data);
            }

            // Log to system logger
            $this->logger->info("Audit log created: {$type}", [
                'log_id' => $logId,
                'is_critical' => $isCritical
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log error but don't throw to prevent audit logging from breaking main flow
            $this->logger->error('Failed to create audit log', [
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function createCriticalAuditLog(int $auditLogId, array $data): void
    {
        DB::table('critical_audit_logs')->insert([
            'audit_log_id' => $auditLogId,
            'full_data' => json_encode($data),
            'system_state' => json_encode($this->captureSystemState()),
            'created_at' => time()
        ]);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'disk_usage' => disk_free_space('/'),
            'active_users' => DB::table('sessions')->count(),
            'error_count' => DB::table('error_logs')
                ->where('created_at', '>', time() - 3600)
                ->count()
        ];
    }
}
