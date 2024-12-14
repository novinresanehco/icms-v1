<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Config;

class SecurityConfig
{
    private array $config;
    private array $securityConstraints;
    private array $metricThresholds;

    public function __construct()
    {
        $this->config = Config::get('security');
        $this->securityConstraints = $this->initializeConstraints();
        $this->metricThresholds = $this->initializeThresholds();
    }

    public function getHashingKey(): string
    {
        return $this->config['hashing']['primary_key'];
    }

    public function getBackupHashingKey(): string
    {
        return $this->config['hashing']['backup_key'];
    }

    public function getHashingAlgorithms(): array
    {
        return $this->config['hashing']['algorithms'];
    }

    public function getKeyRotationInterval(): int
    {
        return $this->config['hashing']['key_rotation_interval'];
    }

    public function getTokenMaxAge(): int
    {
        return $this->config['token']['max_age'];
    }

    public function getSecurityTeam(): array
    {
        return $this->config['notifications']['security_team'];
    }

    public function getEmergencyContacts(): array
    {
        return $this->config['notifications']['emergency_contacts'];
    }

    public function getSystemAdmins(): array
    {
        return $this->config['notifications']['system_admins'];
    }

    public function getMetricThresholds(): array
    {
        return $this->metricThresholds;
    }

    public function getSecurityConstraints(): array
    {
        return $this->securityConstraints;
    }

    public function getFailureThreshold(): int
    {
        return $this->config['thresholds']['failure_count'];
    }

    public function getFailedAccessThreshold(): int
    {
        return $this->config['thresholds']['failed_access'];
    }

    public function getSuspiciousPatterns(): array
    {
        return $this->config['security']['suspicious_patterns'];
    }

    public function getCriticalErrorCode(): int
    {
        return $this->config['errors']['critical_code'];
    }

    public function getHighSeverityEvents(): array
    {
        return $this->config['security']['high_severity_events'];
    }

    public function hasExternalLogging(): bool
    {
        return $this->config['logging']['external_enabled'];
    }

    public function getExternalLogConfig(): array
    {
        return $this->config['logging']['external_config'];
    }

    public function getRequiredPermissions(): array
    {
        return $this->config['security']['required_permissions'];
    }

    private function initializeConstraints(): array
    {
        return [
            new SecurityConstraint(
                'input_validation',
                $this->config['constraints']['input_validation']
            ),
            new SecurityConstraint(
                'access_control',
                $this->config['constraints']['access_control']
            ),
            new SecurityConstraint(
                'data_integrity',
                $this->config['constraints']['data_integrity']
            ),
            new SecurityConstraint(
                'encryption',
                $this->config['constraints']['encryption']
            ),
            new SecurityConstraint(
                'audit_logging',
                $this->config['constraints']['audit_logging']
            )
        ];
    }

    private function initializeThresholds(): array
    {
        return [
            'response_time' => [
                'warning' => 200,
                'critical' => 500
            ],
            'memory_usage' => [
                'warning' => 75,
                'critical' => 90
            ],
            'cpu_usage' => [
                'warning' => 70,
                'critical' => 85
            ],
            'error_rate' => [
                'warning' => 1,
                'critical' => 5
            ],
            'failed_login_attempts' => [
                'warning' => 5,
                'critical' => 10
            ],
            'suspicious_activities' => [
                'warning' => 3,
                'critical' => 7
            ],
            'concurrent_sessions' => [
                'warning' => 50,
                'critical' => 100
            ],
            'api_rate_limit' => [
                'warning' => 1000,
                'critical' => 2000
            ],
            'database_connections' => [
                'warning' => 80,
                'critical' => 95
            ],
            'cache_hit_ratio' => [
                'warning' => 70,
                'critical' => 50
            ]
        ];
    }
}

class SecurityConstraint
{
    private string $name;
    private array $config;

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->config = $config;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function validate(array $context): bool
    {
        return match($this->name) {
            'input_validation' => $this->validateInput($context),
            'access_control' => $this->validateAccess($context),
            'data_integrity' => $this->validateIntegrity($context),
            'encryption' => $this->validateEncryption($context),
            'audit_logging' => $this->validateAuditLogging($context),
            default => throw new \InvalidArgumentException("Unknown constraint: {$this->name}")
        };
    }

    private function validateInput(array $context): bool
    {
        return isset($context['input_validation']) &&
               $context['input_validation']['sanitized'] &&
               $context['input_validation']['validated'];
    }

    private function validateAccess(array $context): bool
    {
        return isset($context['access_control']) &&
               $context['access_control']['authenticated'] &&
               $context['access_control']['authorized'];
    }

    private function validateIntegrity(array $context): bool
    {
        return isset($context['data_integrity']) &&
               $context['data_integrity']['verified'] &&
               $context['data_integrity']['hash_valid'];
    }

    private function validateEncryption(array $context): bool
    {
        return isset($context['encryption']) &&
               $context['encryption']['enabled'] &&
               $context['encryption']['algorithm'] === 'AES-256-GCM';
    }

    private function validateAuditLogging(array $context): bool
    {
        return isset($context['audit_logging']) &&
               $context['audit_logging']['enabled'] &&
               $context['audit_logging']['complete'];
    }
}
