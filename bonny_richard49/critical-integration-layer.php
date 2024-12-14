<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Auth\AuthenticationInterface;
use App\Core\CMS\CMSManagerInterface;
use App\Core\Template\TemplateManagerInterface;
use App\Core\Infrastructure\InfrastructureInterface;

class CriticalIntegrationLayer implements IntegrationInterface
{
    private SecurityManagerInterface $security;
    private AuthenticationInterface $auth;
    private CMSManagerInterface $cms;
    private TemplateManagerInterface $template;
    private InfrastructureInterface $infrastructure;
    private MetricsCollector $metrics;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManagerInterface $security,
        AuthenticationInterface $auth,
        CMSManagerInterface $cms,
        TemplateManagerInterface $template,
        InfrastructureInterface $infrastructure,
        MetricsCollector $metrics,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cms = $cms;
        $this->template = $template;
        $this->infrastructure = $infrastructure;
        $this->metrics = $metrics;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Initialize critical system components
     */
    public function initializeSystem(): SystemStatus
    {
        return $this->security->executeCriticalOperation(
            new SystemOperation('initialize'),
            function() {
                // Initialize infrastructure first
                $infra = $this->infrastructure->initialize();
                if (!$infra->isReady()) {
                    throw new SystemInitializationException('Infrastructure initialization failed');
                }

                // Verify auth system
                $auth = $this->auth->verifySystem();
                if (!$auth->isOperational()) {
                    throw new SystemInitializationException('Authentication system verification failed');
                }

                // Check CMS readiness
                $cms = $this->cms->verifySystem();
                if (!$cms->isOperational()) {
                    throw new SystemInitializationException('CMS system verification failed');
                }

                // Validate template system
                $template = $this->template->verifySystem();
                if (!$template->isOperational()) {
                    throw new SystemInitializationException('Template system verification failed');
                }

                // Start system monitoring
                $this->startSystemMonitoring();

                return new SystemStatus(true, [
                    'infrastructure' => $infra,
                    'auth' => $auth,
                    'cms' => $cms,
                    'template' => $template
                ]);
            }
        );
    }

    /**
     * Handle critical system request with full security
     */
    public function handleRequest(RequestContext $context): ResponseResult
    {
        return $this->security->executeCriticalOperation(
            new RequestOperation($context),
            function() use ($context) {
                // Verify authentication
                $auth = $this->auth->authenticate($context->getCredentials());
                if (!$auth->isValid()) {
                    throw new AuthenticationException('Invalid authentication');
                }

                // Process request based on type
                switch ($context->getType()) {
                    case 'content':
                        return $this->handleContentRequest($context, $auth);
                    case 'template':
                        return $this->handleTemplateRequest($context, $auth);
                    case 'system':
                        return $this->handleSystemRequest($context, $auth);
                    default:
                        throw new InvalidRequestException('Unknown request type');
                }
            }
        );
    }

    /**
     * Monitor system health with integrated metrics
     */
    public function monitorSystemHealth(): HealthStatus
    {
        $metrics = [
            'auth' => $this->auth->getHealthMetrics(),
            'cms' => $this->cms->getHealthMetrics(),
            'template' => $this->template->getHealthMetrics(),
            'infrastructure' => $this->infrastructure->monitorHealth()
        ];

        // Log health status
        $this->auditLogger->logSystemHealth($metrics);

        // Check critical thresholds
        foreach ($metrics as $component => $status) {
            if (!$status->isHealthy()) {
                $this->handleUnhealthyComponent($component, $status);
            }
        }

        return new HealthStatus($metrics);
    }

    /**
     * Handle component failure with recovery
     */
    private function handleUnhealthyComponent(string $component, ComponentStatus $status): void
    {
        // Log issue
        $this->auditLogger->logComponentIssue($component, $status);

        // Execute component-specific recovery
        switch ($component) {
            case 'auth':
                $this->recoverAuthSystem();
                break;
            case 'cms':
                $this->recoverCMSSystem();
                break;
            case 'template':
                $this->recoverTemplateSystem();
                break;
            case 'infrastructure':
                $this->recoverInfrastructure();
                break;
        }

        // Verify recovery
        $newStatus = $this->verifyComponentHealth($component);
        if (!$newStatus->isHealthy()) {
            throw new SystemFailureException("Failed to recover {$component}");
        }
    }

    /**
     * Start comprehensive system monitoring
     */
    private function startSystemMonitoring(): void
    {
        $this->metrics->startCollection([
            'auth_metrics' => [
                'login_attempts',
                'active_sessions',
                'auth_failures'
            ],
            'cms_metrics' => [
                'content_operations',
                'media_usage',
                'database_performance'
            ],
            'template_metrics' => [
                'render_times',
                'cache_hits',
                'compile_times'
            ],
            'infrastructure_metrics' => [
                'system_resources',
                'response_times',
                'error_rates'
            ]
        ]);
    }

    /**
     * Verify specific component health
     */
    private function verifyComponentHealth(string $component): ComponentStatus
    {
        switch ($component) {
            case 'auth':
                return $this->auth->verifySystem();
            case 'cms':
                return $this->cms->verifySystem();
            case 'template':
                return $this->template->verifySystem();
            case 'infrastructure':
                return $this->infrastructure->verifySystem();
            default:
                throw new InvalidComponentException('Unknown component');
        }
    }
}
