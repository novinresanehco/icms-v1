<?php

namespace App\Core\Security\Integration;

final class SecurityIntegration
{
    private AccessControl $access;
    private EncryptionService $encryption;
    private AuditLogger $audit;

    public function validateAccess(Request $request): void
    {
        if (!$this->access->validateToken($request->getToken())) {
            throw new SecurityException('Invalid token');
        }

        if (!$this->access->checkPermissions($request->getContext())) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    public function secureData(array $data): array
    {
        $encrypted = $this->encryption->encrypt(json_encode($data));
        $this->audit->logDataAccess($data);
        return ['data' => $encrypted, 'signature' => $this->sign($encrypted)];
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->encryption->getKey());
    }
}

class SecurityException extends \Exception {}
