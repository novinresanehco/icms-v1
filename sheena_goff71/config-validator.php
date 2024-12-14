<?php

namespace App\Core\Config;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Exceptions\ConfigurationException;

class ConfigurationValidator implements ConfigurationValidatorInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private array $requiredSettings;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->requiredSettings = $this->getRequiredSettings();
    }

    /**
     * Validate all critical configuration settings
     */
    public function validateConfiguration(array $config): void
    {
        $operationId = $this->monitor->startOperation('config.validation');

        try {
            // Validate security configuration first
            $this->validateSecurityConfig($config['security']);

            // Validate CMS configuration
            $this->validateCMSConfig($config['cms']);

            // Validate infrastructure configuration
            $this->validateInfrastructureConfig($config['infrastructure']);

            // Verify integrity of entire configuration
            $this->verifyConfigurationIntegrity($config);

            $this->monitor->recordMetric('config.validation.success', 1);

        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Validate critical security settings
     */
    private function validateSecurityConfig(array $config): void
    {
        // Validate encryption settings
        if ($config['encryption']['algorithm'] !== 'AES-256-GCM') {
            throw new ConfigurationException('Invalid encryption algorithm. AES-256-GCM required.');
        }

        // Validate key rotation
        if ($config['encryption']['key_rotation'] > 24) {
            throw new ConfigurationException('Key rotation period exceeds maximum allowed time.');
        }

        // Validate authentication
        if (!$config['authentication']['multi_factor']) {
            throw new ConfigurationException('Multi-factor authentication must be enabled.');
        }

        // Validate session security
        if ($config['authentication']['session_lifetime'] > 15) {
            throw new ConfigurationException('Session lifetime exceeds security policy.');
        }

        // Validate access control
        if (!$config['access_control']['strict_mode']) {
            throw new ConfigurationException('Strict access control mode must be enabled.');
        }
    }

    /**
     * Validate CMS configuration
     */
    private function validateCMSConfig(array $config): void
    {
        // Validate content security
        foreach ($config['content']['validation']['allowed_tags'] as $tag) {
            if (!in_array($tag, $this->getAllowedHtmlTags())) {
                throw new ConfigurationException("Invalid HTML tag in configuration: $tag");
            }
        }

        // Validate media security
        foreach ($config['media']['processing']['allowed_types'] as $type) {
            if (!in_array($type, $this->getAllowedMimeTypes())) {
                throw new ConfigurationException("Invalid MIME type in configuration: $type");
            }
        }

        // Validate storage security
        if (!$config['media']['storage']['url_signing']) {
            throw new ConfigurationException('URL signing must be enabled for media storage.');
        }
    }

    /**
     * Validate infrastructure configuration
     */
    private function validateInfrastructureConfig(array $config): void
    {
        // Validate performance thresholds
        if ($config['performance']['max_execution_time'] > 30) {
            throw new ConfigurationException('Max execution time exceeds allowed limit.');
        }

        // Validate monitoring settings
        if ($config['monitoring']['metrics_interval'] > 60) {
            throw new ConfigurationException('Metrics interval exceeds recommended threshold.');
        }

        // Validate caching configuration
        if ($config['caching']['driver'] !== 'redis') {
            throw new ConfigurationException('Redis is required for caching.');
        }

        // Validate database settings
        if ($config['database']['query_timeout'] > 5) {
            throw new ConfigurationException('Database query timeout exceeds safe limit.');
        }
    }

    /**
     * Verify integrity of entire configuration
     */
    private function verifyConfigurationIntegrity(array $config): void
    {
        // Check for missing required settings
        foreach ($this->requiredSettings as $path => $requirement) {
            if (!$this->hasConfigPath($config, $path)) {
                throw new ConfigurationException("Missing required configuration: $path");
            }
        }

        // Verify configuration relationships
        $this->verifyConfigurationRelationships($config);

        // Verify security dependencies
        $this->verifySecurityDependencies($config);

        // Validate environment compatibility
        $this->validateEnvironmentCompatibility($config);
    }

    /**
     * Verify relationships between configuration settings
     */
    private function verifyConfigurationRelationships(array $config): void
    {
        // Verify cache-security relationship
        if ($config['infrastructure']['caching']['enabled'] && 
            !$config['security']['encryption']['enabled']) {
            throw new ConfigurationException('Cache encryption must be enabled when caching is active.');
        }

        // Verify monitoring-security relationship
        if ($config['infrastructure']['monitoring']['enabled'] && 
            !$config['security']['audit']['enabled']) {
            throw new ConfigurationException('Security audit must be enabled with monitoring.');
        }
    }

    private function handleValidationFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->recordMetric('config.validation.failure', 1);
        
        $this->monitor->triggerAlert('configuration_validation_failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getAllowedHtmlTags(): array
    {
        return ['p', 'h1', 'h2', 'h3', 'strong', 'em', 'ul', 'ol', 'li'];
    }

    private function getAllowedMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    }

    private function getRequiredSettings(): array
    {
        // Define all required configuration settings
        return require config_path('required-settings.php');
    }
}
