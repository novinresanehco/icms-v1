<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityCore;
use App\Core\Integration\IntegrationService;
use App\Core\Cache\CacheManager;
use App\Exceptions\IntegrationException;

class CMSIntegrationManager implements CMSIntegrationInterface
{
    private SecurityCore $security;
    private IntegrationService $integration;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityCore $security,
        IntegrationService $integration,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->integration = $integration;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function integrateService(string $service, array $config, SecurityContext $context): IntegrationResult
    {
        return $this->security->validateSecureOperation(function() use ($service, $config, $context) {
            // Validate integration config
            $validatedConfig = $this->validateIntegrationConfig($config);
            
            return DB::transaction(function() use ($service, $validatedConfig, $context) {
                // Register integration
                $integration = $this->registerIntegration($service, $validatedConfig);
                
                // Configure security
                $this->configureIntegrationSecurity($integration, $context);
                
                // Initialize service
                $this->initializeIntegrationService($integration);
                
                // Verify integration
                $this->verifyIntegration($integration);
                
                return new IntegrationResult($integration);
            });
        }, $context);
    }

    private function validateIntegrationConfig(array $config): array
    {
        $validator = new IntegrationConfigValidator($this->config['validation_rules']);
        return $validator->validate($config);
    }

    private function registerIntegration(string $service, array $config): Integration
    {
        // Create integration record
        $integration = $this->integrationRepository->create([
            'service' => $service,
            'config' => $config,
            'status' => 'initializing',
            'created_at' => now()
        ]);

        // Register with integration service
        $this->integration->registerService($service, $integration->id);

        return $integration;
    }

    private function configureIntegrationSecurity(Integration $integration, SecurityContext $context): void
    {
        // Set up access controls
        $this->securityRepository->createIntegrationSecurity([
            'integration_id' => $integration->id,
            'access_key' => $this->security->generateAccessKey(),
            'secret_key' => $this->security->generateSecretKey(),
            'permissions' => $this->resolveIntegrationPermissions($integration, $context)
        ]);

        // Configure authentication
        $this->configureIntegrationAuth($integration);

        // Set up monitoring
        $this->configureIntegrationMonitoring($integration);
    }

    private function initializeIntegrationService(Integration $integration): void
    {
        try {
            // Initialize service connection
            $connection = $this->integration->initializeService(
                $integration->service,
                $integration->config
            );

            // Verify connection
            if (!$connection->verify()) {
                throw new IntegrationException('Service connection verification failed');
            }

            // Update status
            $integration->update(['status' => 'active']);

        } catch (\Throwable $e) {
            $integration->update(['status' => 'failed']);
            throw new IntegrationException(
                'Service initialization failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function verifyIntegration(Integration $integration): void
    {
        // Verify configuration
        if (!$this->verifyIntegrationConfig($integration)) {
            throw new IntegrationException('Integration configuration verification failed');
        }

        // Verify connectivity
        if (!$this->verifyIntegrationConnectivity($integration)) {
            throw new IntegrationException('Integration connectivity verification failed');
        }

        // Verify security
        if (!$this->verifyIntegrationSecurity($integration)) {
            throw new IntegrationException('Integration security verification failed');
        }
    }

    private function resolveIntegrationPermissions(Integration $integration, SecurityContext $context): array
    {
        $basePermissions = $this->config['integration_permissions'][$integration->service] ?? [];
        $contextPermissions = $this->resolveContextPermissions($context);

        return array_intersect($basePermissions, $contextPermissions);
    }

    private function configureIntegrationAuth(Integration $integration): void
    {
        $authConfig = [
            'type' => $this->config['auth_type'][$integration->service] ?? 'token',
            'credentials' => $this->generateAuthCredentials($integration),
            'timeout' => $this->config['auth_timeout'] ?? 3600,
            'refresh' => $this->config['auth_refresh'] ?? true
        ];

        $this->integration->configureAuthentication($integration->id, $authConfig);
    }

    private function configureIntegrationMonitoring(Integration $integration): void
    {
        $monitoringConfig = [
            'metrics' => $this->config['monitoring_metrics'][$integration->service] ?? [],
            'alerts' => $this->config['monitoring_alerts'][$integration->service] ?? [],
            'thresholds' => $this->config['monitoring_thresholds'][$integration->service] ?? []
        ];

        $this->integration->configureMonitoring($integration->id, $monitoringConfig);
    }

    private function verifyIntegrationConfig(Integration $integration): bool
    {
        return $this->integration->verifyServiceConfig(
            $integration->service,
            $integration->config
        );
    }

    private function verifyIntegrationConnectivity(Integration $integration): bool
    {
        $connection = $this->integration->getServiceConnection($integration->id);
        return $connection && $connection->isHealthy();
    }

    private function verifyIntegrationSecurity(Integration $integration): bool
    {
        return $this->security->verifyIntegrationSecurity(
            $integration->id,
            $this->securityRepository->getIntegrationSecurity($integration->id)
        );
    }

    private function generateAuthCredentials(Integration $integration): array
    {
        return [
            'access_key' => $this->security->generateAccessKey(),
            'secret_key' => $this->security->generateSecretKey(),
            'service_id' => $integration->id,
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addHours(24)->timestamp
        ];
    }
}
