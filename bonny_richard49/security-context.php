<?php

namespace App\Core\Security\Context;

use Illuminate\Support\Carbon;
use App\Core\Security\Exceptions\SecurityContextException;
use App\Core\Security\Models\User;
use App\Core\Security\Services\TokenValidator;

class SecurityContext
{
    private User $user;
    private array $permissions;
    private string $ipAddress;
    private array $metadata;
    private Carbon $createdAt;
    private ?Carbon $expiresAt;
    private array $sessionData;
    private bool $isAuthenticated;
    private string $contextId;

    public function __construct(
        User $user,
        array $permissions,
        string $ipAddress,
        array $metadata = [],
        ?Carbon $expiresAt = null
    ) {
        $this->validateConstructorParams($user, $permissions, $ipAddress);

        $this->user = $user;
        $this->permissions = $permissions;
        $this->ipAddress = $ipAddress;
        $this->metadata = $metadata;
        $this->createdAt = Carbon::now();
        $this->expiresAt = $expiresAt;
        $this->sessionData = [];
        $this->isAuthenticated = true;
        $this->contextId = $this->generateContextId();

        $this->initializeContext();
    }

    public static function createFromToken(string $token, TokenValidator $validator): self
    {
        $payload = $validator->validateToken($token);
        
        if (!$payload) {
            throw new SecurityContextException('Invalid security token');
        }

        return new self(
            $payload['user'],
            $payload['permissions'],
            $payload['ip_address'],
            $payload['metadata'] ?? [],
            Carbon::createFromTimestamp($payload['expires_at'])
        );
    }

    public function isValid(): bool
    {
        return $this->isAuthenticated 
            && !$this->isExpired()
            && $this->validatePermissions()
            && $this->validateMetadata();
    }

    public function isExpired(): bool
    {
        if (!$this->expiresAt) {
            return false;
        }

        return $this->expiresAt->isPast();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($permissions, $this->permissions));
    }

    public function hasAllPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->permissions));
    }

    public function validateAccess(string $resource, string $action): bool
    {
        $requiredPermission = "{$resource}:{$action}";
        
        if (!$this->hasPermission($requiredPermission)) {
            $this->logAccessDenied($resource, $action);
            return false;
        }

        return true;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserId(): int
    {
        return $this->user->getId();
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): Carbon
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?Carbon
    {
        return $this->expiresAt;
    }

    public function getContextId(): string
    {
        return $this->contextId;
    }

    public function setSessionData(string $key, $value): void
    {
        $this->validateSessionKey($key);
        $this->sessionData[$key] = $value;
    }

    public function getSessionData(string $key)
    {
        $this->validateSessionKey($key);
        return $this->sessionData[$key] ?? null;
    }

    public function clearSessionData(): void
    {
        $this->sessionData = [];
    }

    public function extend(int $minutes): void
    {
        if (!$this->expiresAt) {
            throw new SecurityContextException('Cannot extend context without expiration');
        }

        $this->expiresAt = $this->expiresAt->addMinutes($minutes);
    }

    public function invalidate(): void
    {
        $this->isAuthenticated = false;
        $this->clearSessionData();
        $this->expiresAt = Carbon::now()->subSecond();
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user->getId(),
            'permissions' => $this->permissions,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->timestamp,
            'expires_at' => $this->expiresAt?->timestamp,
            'is_authenticated' => $this->isAuthenticated,
            'context_id' => $this->contextId
        ];
    }

    private function validateConstructorParams(User $user, array $permissions, string $ipAddress): void
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new SecurityContextException('Invalid IP address');
        }

        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new SecurityContextException('Invalid permission format');
            }
        }

        if (!$user->isActive()) {
            throw new SecurityContextException('Inactive user');
        }
    }

    private function validatePermissions(): bool
    {
        foreach ($this->permissions as $permission) {
            if (!$this->isValidPermissionFormat($permission)) {
                return false;
            }
        }
        return true;
    }

    private function validateMetadata(): bool
    {
        foreach ($this->metadata as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                return false;
            }
        }
        return true;
    }

    private function validateSessionKey(string $key): void
    {
        if (empty($key) || !is_string($key)) {
            throw new SecurityContextException('Invalid session key');
        }
    }

    private function isValidPermissionFormat(string $permission): bool
    {
        return preg_match('/^[a-z]+:[a-z]+$/', $permission) === 1;
    }

    private function initializeContext(): void
    {
        $this->validateContext();
        $this->setupSecurityDefaults();
        $this->initializeSessionData();
    }

    private function validateContext(): void
    {
        if (!$this->user->isActive()) {
            throw new SecurityContextException('User is not active');
        }

        if ($this->expiresAt && $this->expiresAt->isPast()) {
            throw new SecurityContextException('Context is expired');
        }

        if (empty($this->permissions)) {
            throw new SecurityContextException('No permissions defined');
        }
    }

    private function setupSecurityDefaults(): void
    {
        if (!isset($this->metadata['user_agent'])) {
            $this->metadata['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        }

        if (!isset($this->metadata['session_id'])) {
            $this->metadata['session_id'] = session_id() ?: uniqid('sess_', true);
        }
    }

    private function initializeSessionData(): void
    {
        $this->sessionData = [
            'last_activity' => time(),
            'created_at' => $this->createdAt->timestamp,
            'ip_address' => $this->ipAddress
        ];
    }

    private function generateContextId(): string
    {
        return sprintf(
            '%s-%s-%s',
            $this->user->getId(),
            bin2hex(random_bytes(8)),
            time()
        );
    }

    private function logAccessDenied(string $resource, string $action): void
    {
        // Implementation depends on logging system
        // This should be configured through dependency injection
    }
}
