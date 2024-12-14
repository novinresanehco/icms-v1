<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Events\EventDispatcher;
use App\Core\Validation\ValidationService;

class IntegrationService implements IntegrationInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private EventDispatcher $events;
    private ValidationService $validator;
    private ComponentRegistry $registry;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        EventDispatcher $events,
        ValidationService $validator,
        ComponentRegistry $registry
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->events = $events;
        $this->validator = $validator;
        $this->registry = $registry;
    }

    public function verifySystemIntegration(): IntegrationResult
    {
        try {
            // Verify auth integration
            $this->verifyAuthIntegration();
            
            // Verify CMS integration
            $this->verifyCMSIntegration();
            
            // Verify template system
            $this->verifyTemplateIntegration();
            
            // Verify infrastructure
            $this->verifyInfrastructureIntegration();
            
            return new IntegrationResult(true, $this->collectIntegrationMetrics());

        } catch (\Exception $e) {
            $this->handleIntegrationFailure($e);
            throw new IntegrationException('System integration verification failed', 0, $e);
        }
    }

    public function validateComponentCommunication(): CommunicationReport
    {
        $failures = [];
        $metrics = [];

        foreach ($this->registry->getComponents() as $component) {
            try {
                // Verify component health
                $health = $this->verifyComponentHealth($component);
                
                // Test communication
                $commResult = $this->testComponentCommunication($component);
                
                // Collect metrics
                $metrics[$component->getId()] = [
                    'health' => $health,
                    'communication' => $commResult,
                    'latency' => $this->measureLatency($component)
                ];

            } catch (\Exception $e) {
                $failures[] = [
                    'component' => $component->getId(),
                    'error' => $e->getMessage()
                ];
            }
        }

        return new CommunicationReport($metrics, $failures);
    }

    public function monitorIntegrationHealth(): HealthStatus
    {
        try {
            // Check component status
            $componentStatus = $this->checkComponentStatus();
            
            // Verify data flow
            $dataFlowStatus = $this->verifyDataFlow();
            
            // Check security integration
            $securityStatus = $this->verifySecurityIntegration();
            
            return new HealthStatus(
                $componentStatus,
                $dataFlowStatus,
                $securityStatus
            );

        } catch (\Exception $e) {
            $this->handleHealthCheckFailure($e);
            throw new HealthCheckException('Integration health check failed', 0, $e);
        }
    }

    private function verifyAuthIntegration(): void
    {
        // Verify auth service
        $this->verifyService('auth', function($service) {
            $this->testAuthFlow($service);
            $this->validateAuthSecurity($service);
            $this->checkAuthPerformance($service);
        });
    }

    private function verifyCMSIntegration(): void
    {
        // Verify CMS service
        $this->verifyService('cms', function($service) {
            $this->testContentFlow($service);
            $this->validateContentSecurity($service);
            $this->checkCMSPerformance($service);
        });
    }

    private function verifyTemplateIntegration(): void
    {
        // Verify template service
        $this->verifyService('template', function($service) {
            $this->testTemplateRendering($service);
            $this->validateTemplateSecurity($service);
            $this->checkTemplatePerformance($service);
        });
    }

    private function verifyInfrastructureIntegration(): void
    {
        // Verify infrastructure services
        $this->verifyService('infrastructure', function($service) {
            $this->testInfrastructureComponents($service);
            $this->validateInfrastructureSecurity($service);
            $this->checkInfrastructurePerformance($service);
        });
    }

    private function verifyService(string $name, callable $validator): void
    {
        $service = $this->registry->getService($name);
        
        if (!$service->isAvailable()) {
            throw new ServiceUnavailableException("Service $name is not available");
        }

        $validator($service);
        
        $this->metrics->recordServiceCheck($name, true);
    }

    private function verifyComponentHealth(Component $component): bool
    {
        // Check component status
        if (!$component->isHealthy()) {
            return false;
        }

        // Verify resource usage
        if (!$this->checkComponentResources($component)) {
            return false;
        }

        // Check error rates
        if (!$this->verifyErrorRates($component)) {
            return false;
        }

        return true;
    }

    private function testComponentCommunication(Component $component): bool
    {
        // Test API communication
        $apiTest = $this->testAPI($component);
        
        // Verify event handling
        $eventTest = $this->testEventHandling($component);
        
        // Check data exchange
        $dataTest = $this->testDataExchange($component);
        
        return $apiTest && $eventTest && $dataTest;
    }

    private function collectIntegrationMetrics(): array
    {
        return [
            'response_times' => $this->metrics->getResponseTimes(),
            'error_rates' => $this->metrics->getErrorRates(),
            'resource_usage' => $this->metrics->getResourceUsage(),
            'security_status' => $this->security->getSecurityMetrics()
        ];
    }
}
