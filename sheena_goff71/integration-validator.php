<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationManager;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\InfrastructureManager;
use App\Core\Integration\Exceptions\{IntegrationException, ValidationException};

class IntegrationValidator
{
    protected SecurityManager $security;
    protected AuthenticationManager $auth;
    protected ContentManager $content;
    protected TemplateManager $template;
    protected InfrastructureManager $infrastructure;
    protected AuditLogger $auditLogger;
    protected MonitoringService $monitor;

    private const MAX_VALIDATION_TIME = 30; // seconds
    private const CRITICAL_ERROR_THRESHOLD = 1;
    private const PERFORMANCE_THRESHOLD = 200; // ms

    public function __construct(
        SecurityManager $security,
        AuthenticationManager $auth,
        ContentManager $content,
        TemplateManager $template,
        InfrastructureManager $infrastructure,
        AuditLogger $auditLogger,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->content = $content;
        $this->template = $template;
        $this->infrastructure = $infrastructure;
        $this->auditLogger = $auditLogger;
        $this->monitor = $monitor;
    }

    /**
     * Execute comprehensive integration validation
     */
    public function validateSystemIntegration(): ValidationResult
    {
        return $this->security->executeCriticalOperation(function() {
            $startTime = microtime(true);
            $result = new ValidationResult();
            
            try {
                // Verify core system health
                $this->validateSystemHealth($result);
                
                // Validate security integration
                $this->validateSecurityIntegration($result);
                
                // Test auth flow integration
                $this->validateAuthFlow($result);
                
                // Verify CMS integration
                $this->validateCmsIntegration($result);
                
                // Check template system
                $this->validateTemplateIntegration($result);
                
                // Validate complete workflow
                $this->validateEndToEndFlow($result);
                
                // Performance validation
                $this->validateSystemPerformance($result);
                
                $executionTime = microtime(true) - $startTime;
                if ($executionTime > self::MAX_VALIDATION_TIME) {
                    $result->addWarning('Validation execution time exceeded threshold');
                }
                
                $this->auditLogger->logIntegrationValidation($result);
                
            } catch (\Throwable $e) {
                $this->handleValidationFailure($e, $result);
            }
            
            return $result;
        }, ['context' => 'integration_validation']);
    }

    /**
     * Validate system health across all components
     */
    protected function validateSystemHealth(ValidationResult $result): void
    {
        // Check infrastructure health
        $healthStatus = $this->infrastructure->monitorSystemHealth();
        if (!$healthStatus->isHealthy()) {
            throw new IntegrationException('System health check failed');
        }

        // Verify all critical services
        $serviceStatus = $this->validateCriticalServices();
        $result->addResults('system_health', $serviceStatus);

        // Check resource availability
        $resourceStatus = $this->infrastructure->checkResourceAvailability();
        $result->addResults('resources', $resourceStatus);
    }

    /**
     * Validate security integration across components
     */
    protected function validateSecurityIntegration(ValidationResult $result): void
    {
        // Verify security configuration
        $securityConfig = $this->security->validateConfiguration();
        $result->addResults('security_config', $securityConfig);

        // Test encryption system
        $encryptionTest = $this->validateEncryptionSystem();
        $result->addResults('encryption', $encryptionTest);

        // Validate audit logging
        $auditTest = $this->validateAuditSystem();
        $result->addResults('audit', $auditTest);
    }

    /**
     * Validate authentication flow integration
     */
    protected function validateAuthFlow(ValidationResult $result): void
    {
        // Test authentication process
        $authTest = $this->testAuthenticationFlow();
        $result->addResults('authentication', $authTest);

        // Verify session management
        $sessionTest = $this->validateSessionManagement();
        $result->addResults('sessions', $sessionTest);

        // Check permission system
        $permissionTest = $this->validatePermissionSystem();
        $result->addResults('permissions', $permissionTest);
    }

    /**
     * Validate CMS integration with other components
     */
    protected function validateCmsIntegration(ValidationResult $result): void
    {
        // Test content operations
        $contentTest = $this->validateContentOperations();
        $result->addResults('content', $contentTest);

        // Verify media handling
        $mediaTest = $this->validateMediaSystem();
        $result->addResults('media', $mediaTest);

        // Check versioning system
        $versionTest = $this->validateVersioningSystem();
        $result->addResults('versioning', $versionTest);
    }

    /**
     * Validate template system integration
     */
    protected function validateTemplateIntegration(ValidationResult $result): void
    {
        // Test template compilation
        $compilationTest = $this->validateTemplateCompilation();
        $result->addResults('template_compilation', $compilationTest);

        // Verify rendering system
        $renderTest = $this->validateTemplateRendering();
        $result->addResults('template_rendering', $renderTest);

        // Check cache integration
        $cacheTest = $this->validateTemplateCache();
        $result->addResults('template_cache', $cacheTest);
    }

    /**
     * Validate end-to-end system workflow
     */
    protected function validateEndToEndFlow(ValidationResult $result): void
    {
        try {
            // Create test user
            $user = $this->createTestUser();
            
            // Test authentication
            $auth = $this->auth->authenticate($user->credentials);
            
            // Create content
            $content = $this->content->createContent([
                'title' => 'Test Content',
                'body' => 'Integration Test Content'
            ], $user);
            
            // Create and render template
            $template = $this->template->registerTemplate([
                'name' => 'Test Template',
                'content' => 'Test: {{ content.title }}'
            ], $user);
            
            $rendered = $this->template->renderTemplate(
                $template->id,
                ['content' => $content->toArray()]
            );
            
            $result->addSuccess('end_to_end', 'Complete workflow validation successful');
            
        } catch (\Throwable $e) {
            $result->addError('end_to_end', 'Workflow validation failed: ' . $e->getMessage());
            throw $e;
        } finally {
            // Cleanup test data
            $this->cleanupTestData();
        }
    }

    /**
     * Handle validation failure
     */
    protected function handleValidationFailure(\Throwable $e, ValidationResult $result): void
    {
        $result->addError('validation_failure', $e->getMessage());
        
        $this->auditLogger->logValidationFailure($e);
        
        if ($result->getErrorCount() >= self::CRITICAL_ERROR_THRESHOLD) {
            throw new IntegrationException(
                'Critical integration validation failed',
                previous: $e
            );
        }
    }

    /**
     * Validate system performance
     */
    protected function validateSystemPerformance(ValidationResult $result): void
    {
        $metrics = $this->monitor->getPerformanceMetrics();
        
        foreach ($metrics as $metric => $value) {
            if ($value > self::PERFORMANCE_THRESHOLD) {
                $result->addWarning("Performance threshold exceeded for $metric");
            }
        }
        
        $result->addResults('performance', $metrics);
    }
}
