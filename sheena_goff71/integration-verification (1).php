<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationSystem;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\InfrastructureManager;

class IntegrationVerifier implements IntegrationInterface
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;
    private ContentManager $cms;
    private TemplateManager $template;
    private InfrastructureManager $infrastructure;

    public function __construct(
        SecurityManager $security,
        AuthenticationSystem $auth,
        ContentManager $cms,
        TemplateManager $template,
        InfrastructureManager $infrastructure
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cms = $cms;
        $this->template = $template;
        $this->infrastructure = $infrastructure;
    }

    public function verifySystemIntegration(): IntegrationReport
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeIntegrationVerification(),
            ['action' => 'integration_verification']
        );
    }

    private function executeIntegrationVerification(): IntegrationReport
    {
        $report = new IntegrationReport();

        try {
            // Verify auth integration
            $report->addResult('auth', $this->verifyAuthIntegration());

            // Verify CMS integration
            $report->addResult('cms', $this->verifyCMSIntegration());

            // Verify template integration
            $report->addResult('template', $this->verifyTemplateIntegration());

            // Verify infrastructure integration
            $report->addResult('infrastructure', $this->verifyInfrastructureIntegration());

            // Verify cross-component integration
            $report->addResult('cross_component', $this->verifyCrossComponentIntegration());

            return $report;

        } catch (\Exception $e) {
            $this->handleVerificationFailure($e);
            throw new IntegrationException(
                'Integration verification failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function verifyAuthIntegration(): ComponentVerificationResult
    {
        $result = new ComponentVerificationResult('Authentication');

        // Verify authentication flow
        $result->addCheck('auth_flow', $this->verifyAuthenticationFlow());

        // Verify session management
        $result->addCheck('session', $this->verifySessionManagement());

        // Verify permission system
        $result->addCheck('permissions', $this->verifyPermissionSystem());

        // Verify token management
        $result->addCheck('tokens', $this->verifyTokenManagement());

        // Verify security integration
        $result->addCheck('security', $this->verifyAuthSecurityIntegration());

        return $result;
    }

    private function verifyCMSIntegration(): ComponentVerificationResult
    {
        $result = new ComponentVerificationResult('CMS');

        // Verify content management
        $result->addCheck('content', $this->verifyContentManagement());

        // Verify media handling
        $result->addCheck('media', $this->verifyMediaHandling());

        // Verify caching integration
        $result->addCheck('caching', $this->verifyCMSCaching());

        // Verify security integration
        $result->addCheck('security', $this->verifyCMSSecurityIntegration());

        return $result;
    }

    private function verifyTemplateIntegration(): ComponentVerificationResult
    {
        $result = new ComponentVerificationResult('Template');

        // Verify template rendering
        $result->addCheck('rendering', $this->verifyTemplateRendering());

        // Verify component system
        $result->addCheck('components', $this->verifyComponentSystem());

        // Verify caching integration
        $result->addCheck('caching', $this->verifyTemplateCaching());

        // Verify security integration
        $result->addCheck('security', $this->verifyTemplateSecurityIntegration());

        return $result;
    }

    private function verifyInfrastructureIntegration(): ComponentVerificationResult
    {
        $result = new ComponentVerificationResult('Infrastructure');

        // Verify monitoring system
        $result->addCheck('monitoring', $this->verifyMonitoringSystem());

        // Verify backup system
        $result->addCheck('backup', $this->verifyBackupSystem());

        // Verify caching system
        $result->addCheck('caching', $this->verifyCachingSystem());

        // Verify security integration
        $result->addCheck('security', $this->verifyInfrastructureSecurityIntegration());

        return $result;
    }

    private function verifyCrossComponentIntegration(): ComponentVerificationResult
    {
        $result = new ComponentVerificationResult('Cross-Component');

        // Verify auth-cms integration
        $result->addCheck('auth_cms', $this->verifyAuthCMSIntegration());

        // Verify cms-template integration
        $result->addCheck('cms_template', $this->verifyCMSTemplateIntegration());

        // Verify template-auth integration
        $result->addCheck('template_auth', $this->verifyTemplateAuthIntegration());

        // Verify infrastructure-wide integration
        $result->addCheck('infrastructure_wide', $this->verifyInfrastructureWideIntegration());

        return $result;
    }

    private function verifyAuthenticationFlow(): bool
    {
        try {
            // Test user authentication
            $credentials = ['username' => 'test_user', 'password' => 'test_pass'];
            $authResult = $this->auth->authenticate($credentials);

            // Verify token generation
            $token = $authResult->getToken();
            $validationResult = $this->auth->validateToken($token);

            return $authResult->isSuccess() && $validationResult->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function verifyContentManagement(): bool
    {
        try {
            // Test content creation
            $content = $this->cms->create([
                'title' => 'Test Content',
                'body' => 'Test body'
            ]);

            // Verify content retrieval
            $retrieved = $this->cms->find($content->getId());

            return $content->getId() === $retrieved->getId();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function verifyTemplateRendering(): bool
    {
        try {
            // Test template rendering
            $rendered = $this->template->render('test', [
                'title' => 'Test Title',
                'content' => 'Test content'
            ]);

            return !empty($rendered) && strpos($rendered, 'Test Title') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function verifyInfrastructureWideIntegration(): bool
    {
        try {
            // Verify system status
            $status = $this->infrastructure->monitorSystem();

            // Check all critical systems
            return $status->isHealthy() &&
                   $status->isSecure() &&
                   $status->hasRequiredResources();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function handleVerificationFailure(\Exception $e): void
    {
        // Log failure details
        $this->infrastructure->logCriticalError('integration_verification_failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);

        // Notify system administrators
        $this->infrastructure->notifyAdministrators(
            'Integration Verification Failure',
            $e->getMessage()
        );
    }
}
