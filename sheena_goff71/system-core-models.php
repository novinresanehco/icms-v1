<?php

namespace App\Core\Models;

class SecurityContext
{
    public string $userId;
    public array $roles;
    public array $permissions;
    public ?string $token;
    public array $metadata;

    public function __construct(array $data)
    {
        $this->userId = $data['user_id'];
        $this->roles = $data['roles'];
        $this->permissions = $data['permissions'] ?? [];
        $this->token = $data['token'] ?? null;
        $this->metadata = $data['metadata'] ?? [];
    }

    public function isValid(): bool
    {
        return !empty($this->userId) && !empty($this->roles);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }
}

class ContentResult
{
    public int $id;
    public array $data;
    public array $metadata;
    public string $status;
    public array $permissions;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->data = $data['data'];
        $this->metadata = $data['metadata'];
        $this->status = $data['status'];
        $this->permissions = $data['permissions'];
        $this->createdAt = new \DateTime($data['created_at']);
        $this->updatedAt = new \DateTime($data['updated_at']);
    }

    public function isValid(): bool
    {
        return !empty($this->id) && !empty($this->data);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'data' => $this->data,
            'metadata' => $this->metadata,
            'status' => $this->status,
            'permissions' => $this->permissions,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s')
        ];
    }
}

class ValidationResult
{
    public bool $isValid;
    public array $errors;
    public array $warnings;
    public array $metadata;

    public function __construct(bool $isValid, array $errors = [], array $warnings = [], array $metadata = [])
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->metadata = $metadata;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata
        ];
    }
}

class AuditResult
{
    public string $id;
    public string $type;
    public array $data;
    public string $status;
    public \DateTime $timestamp;
    public array $metadata;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->type = $data['type'];
        $this->data = $data['data'];
        $this->status = $data['status'];
        $this->timestamp = new \DateTime($data['timestamp']);
        $this->metadata = $data['metadata'] ?? [];
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function getFormattedTimestamp(): string
    {
        return $this->timestamp->format('Y-m-d H:i:s.u');
    }
}

class SystemState
{
    public string $id;
    public array $components;
    public array $metrics;
    public array $flags;
    public \DateTime $timestamp;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->components = $data['components'];
        $this->metrics = $data['metrics'];
        $this->flags = $data['flags'];
        $this->timestamp = new \DateTime($data['timestamp']);
    }

    public function isValid(): bool
    {
        return !empty($this->id) && !empty($this->components);
    }

    public function getComponentStatus(string $component): ?string
    {
        return $this->components[$component] ?? null;
    }
}

class PerformanceMetrics
{
    public float $executionTime;
    public int $memoryUsage;
    public float $cpuUsage;
    public int $queryCount;
    public array $customMetrics;

    public function __construct(array $metrics)
    {
        $this->executionTime = $metrics['execution_time'];
        $this->memoryUsage = $metrics['memory_usage'];
        $this->cpuUsage = $metrics['cpu_usage'];
        $this->queryCount = $metrics['query_count'];
        $this->customMetrics = $metrics['custom_metrics'] ?? [];
    }

    public function exceedsThresholds(array $thresholds): bool
    {
        foreach ($thresholds as $metric => $threshold) {
            if (property_exists($this, $metric) && $this->$metric > $threshold) {
                return true;
            }
        }
        return false;
    }
}
