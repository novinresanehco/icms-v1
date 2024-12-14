<?php

namespace App\Core\Security;

class CriticalAuditService
{
    private $storage;
    private $monitor;
    private $security;

    public function logOperation(Operation $op): void 
    {
        try {
            // Secure audit data
            $auditData = [
                'id' => uniqid('audit_', true),
                'operation' => $op->getType(),
                'user' => $op->getUserId(),
                'timestamp' => time(),
                'data' => $op->getAuditData(),
                'hash' => $this->generateHash($op)
            ];

            // Encrypt and store
            $this->storage->storeAudit(
                $this->security->encrypt($auditData)
            );

        } catch (\Exception $e) {
            $this->handleAuditFailure($e, $op);
        }
    }

    private function generateHash(Operation $op): string
    {
        return hash('sha256', json_encode([
            $op->getId(),
            $op->getUserId(),
            $op->getTimestamp()
        ]));
    }

    private function handleAuditFailure(\Exception $e, Operation $op): void
    {
        $this->monitor->logAuditFailure($e, $op);
        throw new AuditException('Audit logging failed');
    }
}
