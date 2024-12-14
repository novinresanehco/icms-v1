<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthManager;
use App\Core\CMS\CMSManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\InfrastructureManager;
use App\Core\Protection\SystemProtection;

class ServiceIntegrator implements ServiceIntegratorInterface
{
    private SecurityManager $security;
    private AuthManager $auth;
    private CMSManager $cms;
    private TemplateManager $templates;
    private InfrastructureManager $infrastructure;
    private SystemProtection $protection;
    private HealthMonitor $monitor;

    public function __construct(
        SecurityManager $security,
        AuthManager $auth,
        CMSManager $cms,
        TemplateManager $templates,
        InfrastructureManager $infrastructure,
        SystemProtection $protection,
        HealthMonitor $monitor
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cms = $cms;
        $this->templates = $templates;
        $this->infrastructure = $infrastructure;
        $this->protection = $protection;
        $this->monitor = $monitor;
    }

    public function initializeServices(): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeServicesInitialization(),
            ['action' => 'initialize_services']
        );
    }

    private function executeServicesInitialization(): void
    {
        // Initialize infrastructure first
        $this->infrastructure->initializeSystem();
        
        // Enable protection layer
        $this->protection->hardenSystem();
        
        // Start authentication services
        $this->auth->initialize();
        
        // Initialize CMS with security context
        $this->cms->initialize($this->getSecurityContext());
        
        // Setup template system
        $this->templates->initialize();
        
        // Start monitoring
        $this->startMonitoring();
    }

    private function startMonitoring(): void
    {
        $this->monitor->registerHealthChecks([
            'auth' => fn() => $this->checkAuthHealth(),
            'cms' => fn() => $this->checkCMSHealth(),
            'templates' => fn() => $this->checkTemplateHealth(),
            'infrastructure' => fn() => $this->checkInfrastructureHealth()
        ]);

        $this->monitor->startContinuousMonitoring();
    }

    public function verifySystemIntegrity(): IntegrityReport
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeIntegrityCheck(),
            ['action' => 'verify_integrity']
        );
    }

    private function executeIntegrityCheck(): IntegrityReport
    {
        $report = new IntegrityReport();

        // Verify component connectivity
        $report->addCheck('connectivity', $this->verifyConnectivity());

        // Check security status
        $report->addCheck('security', $this->protection->monitorSecurityStatus());

        // Verify data integrity
        $report->addCheck('data', $this->verifyDataIntegrity());

        // Check service health
        $report->addCheck('services', $this->monitor->getServicesHealth());

        return $report;
    }

    private function verifyConnectivity(): array
    {
        $results = [];
        
        // Check auth-cms integration
        $results['auth_cms'] = $this->testAuthCMSIntegration();
        
        // Verify template integration
        $results['template_cms'] = $this->testTemplateCMSIntegration();
        
        // Check infrastructure connections
        $results['infrastructure'] = $this->testInfrastructureConnections();
        
        return $results;
    }

    private function testAuthCMSIntegration(): bool
    {
        try {
            // Attempt authentication flow
            $testToken = $this->auth->generateTestToken();
            return $this->cms->validateAuthToken($testToken);
        } catch (\Throwable $e) {
            $this->monitor->recordIntegrationFailure('auth_cms', $e);
            return false;
        }
    }

    private function testTemplateCMSIntegration(): bool
    {
        try {
            // Test template rendering with CMS data
            $testData = $this->cms->getTestContent();
            $rendered = $this->templates->render('test', $testData);
            return !empty($rendered);
        } catch (\Throwable $e) {
            $this->monitor->recordIntegrationFailure('template_cms', $e);
            return false;
        }
    }

    public function getSystemStatus(): SystemStatus
    {
        return $this->cache->remember('system:status', 60, function() {
            return new SystemStatus([
                'security' => $this->protection->monitorSecurityStatus(),
                'services' => $this->monitor->getServicesHealth(),
                'infrastructure' => $this->infrastructure->getSystemStatus(),
                'performance' => $this->getPerformanceMetrics()
            ]);
        });
    }

    private function getPerformanceMetrics(): array
    {
        return [
            'response_times' => $this->monitor->getAverageResponseTimes(),
            'error_rates' => $this->monitor->getErrorRates(),
            'resource_usage' => $this->infrastructure->getResourceUsage(),
            'cache_stats' => $this->monitor->getCacheStatistics()
        ];
    }

    public function handleServiceFailure(ServiceFailureEvent $event): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeFailureResponse($event),
            ['action' => 'handle_failure', 'service' => $event->service]
        );
    }

    private function executeFailureResponse(ServiceFailureEvent $event): void
    {
        // Log failure event
        Log::critical("Service failure detected", [
            'service' => $event->service,
            'error' => $event->error,
            'impact' => $event->impact
        ]);

        // Execute recovery procedure
        $this->executeRecoveryProcedure($event);

        // Notify administrators
        if ($event->severity === 'critical') {
            $this->notifyAdministrators($event);
        }

        // Update monitoring status
        $this->monitor->recordFailureEvent($event);
    }

    private function executeRecoveryProcedure(ServiceFailureEvent $event): void
    {
        switch ($event->service) {
            case 'auth':
                $this->auth->executeFailoverProcedure();
                break;
            case 'cms':
                $this->cms->activateBackupSystem();
                break;
            case 'templates':
                $this->templates->switchToBackupSystem();
                break;
            case 'infrastructure':
                $this->infrastructure->executeEmergencyProtocol();
                break;
        }
    }
}
