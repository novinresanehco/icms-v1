<?php

namespace App\Core\Security;

use App\Core\Monitoring\MonitoringService;
use App\Core\Cache\CacheManager;
use App\Exceptions\ValidationException;

/**
 * Critical security validation service
 * IMPORTANT: All validations must be comprehensive and strict
 */
class ValidationService implements ValidationInterface
{
    private MonitoringService $monitor;
    private CacheManager $cache;
    private array $validationConfig;

    public function __construct(
        MonitoringService $monitor,
        CacheManager $cache,
        array $validationConfig
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->validationConfig = $validationConfig;
    }

    /**
     * Validate token format and structure
     */
    public function validateTokenFormat(string $token): bool
    {
        // Check basic structure
        if (!preg_match('/^[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+$/', $token)) {
            return false;
        }

        try {
            // Decode token parts
            [$header, $payload, $signature] = explode('.', $token);
            
            // Verify header
            $decodedHeader = $this->decodeAndValidateJson(base64_decode($header));
            if (!$this->validateTokenHeader($decodedHeader)) {
                return false;
            }

            // Verify payload structure
            $decodedPayload = $this->decodeAndValidateJson(base64_decode($payload));
            if (!$this->validateTokenPayload($decodedPayload)) {
                return false;
            }

            // Verify signature length
            if (strlen(base64_decode($signature)) !== SIGNATURE_LENGTH) {
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->monitor->recordMetric('validation.token.failure', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate user roles against defined configuration
     */
    public function validateRoles(array $roles): bool
    {
        try {
            foreach ($roles as $role) {
                // Check role exists
                if (!isset($this->validationConfig['valid_roles'][$role])) {
                    return false;
                }

                // Check role hierarchy
                if (!$this->validateRoleHierarchy($role)) {
                    return false;
                }

                // Verify role permissions
                if (!$this->validateRolePermissions($role)) {
                    return false;
                }
            }

            return true;

        } catch (\Throwable $e) {
            $this->monitor->recordMetric('validation.roles.failure', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate permissions array structure and values
     */
    public function validatePermissions(array $permissions): bool
    {
        try {
            foreach ($permissions as $permission) {
                // Validate permission format
                if (!$this->validatePermissionFormat($permission)) {
                    return false;
                }

                // Check permission exists
                if (!$this->permissionExists($permission)) {
                    return false;
                }

                // Validate permission constraints
                if (!$this->validatePermissionConstraints($permission)) {
                    return false;
                }
            }

            return true;

        } catch (\Throwable $e) {
            $this->monitor->recordMetric('validation.permissions.failure', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate data integrity with comprehensive checks
     */
    public function validateDataIntegrity($data): bool
    {
        try {
            // Check data structure
            if (!$this->validateDataStructure($data)) {
                return false;
            }

            // Validate checksums
            if (!$this->validateDataChecksums($data)) {
                return false;
            }

            // Verify data constraints
            if (!$this->validateDataConstraints($data)) {
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->monitor->recordMetric('validation.data.failure', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function validateTokenHeader(array $header): bool
    {
        return isset($header['alg'], $header['typ']) &&
               $header['typ'] === 'JWT' &&
               in_array($header['alg'], $this->validationConfig['allowed_algorithms']);
    }

    private function validateTokenPayload(array $payload): bool
    {
        return isset($payload['sub'], $payload['iat'], $payload['exp']) &&
               is_numeric($payload['iat']) &&
               is_numeric($payload['exp']);
    }

    private function validateRoleHierarchy(string $role): bool
    {
        $hierarchy = $this->validationConfig['role_hierarchy'];
        return isset($hierarchy[$role]) &&
               $this->validateHierarchyChain($role, $hierarchy);
    }

    private function validatePermissionFormat(string $permission): bool
    {
        return preg_match('/^[a-z]+(\.[a-z]+)*$/', $permission);
    }

    private function validateDataStructure($data): bool
    {
        if ($data instanceof DataContainer) {
            return $this->validateContainerStructure($data);
        }
        return is_array($data) && $this->validateArrayStructure($data);
    }

    private function validateDataChecksums($data): bool
    {
        if (!isset($data->checksums)) {
            return false;
        }

        foreach ($data->checksums as $field => $checksum) {
            if (!$this->verifyChecksum($data->$field, $checksum)) {
                return false;
            }
        }

        return true;
    }
}
