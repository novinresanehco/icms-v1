<?php

namespace App\Core\Integration;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationSystem;
use App\Core\CMS\CoreCMSManager;
use App\Core\Template\TemplateEngine;
use App\Core\Infrastructure\InfrastructureManager;
use App\Core\Exceptions\{IntegrationException, ValidationException};

class SystemIntegrationLayer implements IntegrationInterface
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;
    private CoreCMSManager $cms;
    private TemplateEngine $template;
    private InfrastructureManager $infrastructure;
    private IntegrationValidator $validator;

    public function __construct(
        SecurityManager $security,
        AuthenticationSystem $auth,
        CoreCMSManager $cms,
        TemplateEngine $template,
        InfrastructureManager $infrastructure,
        IntegrationValidator $validator
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cms = $cms;
        $this->template = $template;
        $this->infrastructure = $infrastructure;
        $this->validator = $validator;
    }

    public function verifySystemIntegration(): IntegrationStatus
    {
        try {
            // Validate core system states
            $this->validateCoreStates();
            
            // Check integration points
            $this->verifyIntegrationPoints();
            
            // Test critical paths
            $this->verifyCriticalPaths();
            
            // Validate system security
            $this->verifySecurityIntegration();
            
            return new IntegrationStatus(true, $this->collectMetrics());
            
        } catch (\Exception $e) {
            $this->handleIntegrationFailure($e);
            throw new IntegrationException('System integration verification failed', 0, $e);
        }
    }

    public function executeIntegratedOperation(string $operation, array $params): OperationResult
    {
        return $this->security->executeCriticalOperation(function() use ($operation, $params) {
            // Validate operation request
            $this->validateOperation($operation, $params);
            
            // Prepare execution context
            $context = $this->prepareContext($operation, $params);
            
            // Execute with monitoring
            return $this->executeOperation($operation, $params, $context);
        }, ['operation' => $operation]);
    }

    private function validateCoreStates(): void
    {
        $states = [
            'auth' => $this->auth->getSystemState(),
            'cms' => $this->cms->getSystemState(),
            'template' => $this->template->getSystemState(),
            'infrastructure' => $this->infrastructure->getSystemState()
        ];

        if (!$this->validator->validateStates($states)) {
            throw new ValidationException('Core system states validation failed');
        }
    }

    private function verifyIntegrationPoints(): void
    {
        $points = [
            'auth_cms' => $this->verifyAuthCMSIntegration(),
            'cms_template' => $this->verifyCMSTemplateIntegration(),
            'template_infrastructure' => $this->verifyTemplateInfrastructureIntegration(),
            'auth_infrastructure' => $this->verifyAuthInfrastructureIntegration()
        ];

        foreach ($points as $point => $status) {
            if (!$status->isValid()) {
                throw new IntegrationException("Integration point verification failed: $point");
            }
        }
    }

    private function verifyCriticalPaths(): void
    {
        $paths = [
            'authentication_flow' => $this->verifyAuthenticationFlow(),
            'content_management_flow' => $this->verifyContentManagementFlow(),
            'template_rendering_flow' => $this->verifyTemplateRenderingFlow(),
            'infrastructure_monitoring_flow' => $this->verifyInfrastructureMonitoringFlow()
        ];

        foreach ($paths as $path => $status) {
            if (!$status->isValid()) {
                throw new IntegrationException("Critical path verification failed: $path");
            }
        }
    }

    private function verifySecurityIntegration(): void
    {
        $securityChecks = [
            'auth_security' => $this->verifyAuthSecurityIntegration(),
            'cms_security' => $this->verifyCMSSecurityIntegration(),
            'template_security' => $this->verifyTemplateSecurityIntegration(),
            'infrastructure_security' => $this->verifyInfrastructureSecurityIntegration()
        ];

        foreach ($securityChecks as $check => $status) {
            if (!$status->isValid()) {
                throw new SecurityException("Security integration verification failed: $check");
            }
        }
    }

    private function verifyAuthCMSIntegration(): IntegrationPointStatus
    {
        try {
            // Verify authentication flow
            $authResult = $this->auth->validateIntegration();
            
            // Verify CMS integration
            $cmsResult = $this->cms->validateAuthIntegration();
            
            // Verify permissions flow
            $permissionsResult = $this->verifyPermissionsFlow();
            
            return new IntegrationPointStatus(
                $authResult && $cmsResult && $permissionsResult,
                $this->collectPointMetrics('auth_cms')
            );
        } catch (\Exception $e) {
            Log::error('Auth-CMS integration verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function verifyCMSTemplateIntegration(): IntegrationPointStatus
    {
        try {
            // Verify content rendering
            $renderResult = $this->verifyContentRendering();
            
            // Verify template cache integration
            $cacheResult = $this->verifyTemplateCacheIntegration();
            
            // Verify component integration
            $componentResult = $this->verifyComponentIntegration();
            
            return new IntegrationPointStatus(
                $renderResult && $cacheResult && $componentResult,
                $this->collectPointMetrics('cms_template')
            );
        } catch (\Exception $e) {
            Log::error('CMS-Template integration verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function collectMetrics(): array
    {
        return [
            'performance' => $this->collectPerformanceMetrics(),
            'security' => $this->collectSecurityMetrics(),
            'reliability' => $this->collectReliabilityMetrics(),
            'integration' => $this->collectIntegrationMetrics()
        ];
    }

    private function handleIntegrationFailure(\Exception $e): void
    {
        Log::error('Integration failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->collectMetrics()
        ]);

        // Notify monitoring system
        $this->infrastructure->notifyFailure('integration', [
            'error' => $e->getMessage(),
            'component' => $e->getComponent()
        ]);
    }
}
