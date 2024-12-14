<?php

namespace App\Core\Security;

use App\Core\Exceptions\{SecurityException, ValidationException};
use App\Core\Interfaces\{SecurityInterface, AuditInterface};
use Illuminate\Support\Facades\{DB, Log, Cache};

final class SecurityCore implements SecurityInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private MonitorService $monitor;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        MonitorService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->monitor = $monitor;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->monitor->startOperation();
        DB::beginTransaction();

        try {
            $this->validateContext($context);
            $this->verifyAccess($context);
            $this->monitor->checkThresholds();

            $result = $operation();

            $this->validateResult($result);
            $this->audit->logSuccess($operationId, $context);
            
            DB::commit();
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $operationId, $context);
            throw new SecurityException('Operation failed', 0, $e);
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateContext(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }
    }

    private function verifyAccess(array $context): void 
    {
        if (!$this->validator->verifyPermissions($context['permissions'] ?? [])) {
            throw new SecurityException('Access denied');
        }
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Throwable $e, string $operationId, array $context): void 
    {
        $this->audit->logFailure($operationId, $e, $context);
        $this->monitor->recordFailure($operationId);
        Log::critical('Security operation failed', [
            'operation' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
