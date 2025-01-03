<?php

namespace App\Core\Audit;

class AuditLogger implements AuditLoggerInterface
{
    private LoggerClient $client;
    private string $environment;

    public function logAccess(SecurityContext $context): void
    {
        $this->log('access', [
            'user' => $context->getUser()->id,
            'resource' => $context->getResource(),
            'ip' => $context->getIpAddress(),
            'timestamp' => time()
        ]);
    }

    public function logOperation(string $operation, array $data): void
    {
        $this->log('operation', [
            'type' => $operation,
            'data' => $data,
            'timestamp' => time()
        ]);
    }

    public function logFailure(\Exception $e, array $context): void
    {
        $this->log('failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
            'timestamp' => time()
        ]);
    }

    private function log(string $type, array $data): void
    {
        $this->client->log([
            'type' => $type,
            'data' => $data,
            'environment' => $this->environment
        ]);
    }
}
