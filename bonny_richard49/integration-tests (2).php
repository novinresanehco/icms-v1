<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Security\SecurityManager;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\InfrastructureManager;

/**
 * Critical System Integration Tests
 * Validates complete system functionality and security
 */
class CriticalSystemTest extends TestCase
{
    use RefreshDatabase;

    private SecurityManager $security;
    private ContentManager $content;
    private TemplateManager $template;
    private InfrastructureManager $infrastructure;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->security = app(SecurityManager::class);
        $this->content = app(ContentManager::class);
        $this->template = app(TemplateManager::class);
        $this->infrastructure = app(InfrastructureManager::class);

        // Initialize infrastructure
        $this->infrastructure->initialize();
    }

    /**
     * Test complete authentication flow
     */
    public function testAuthenticationFlow(): void
    {
        // Test multi-factor authentication
        $credentials = [
            'username' => 'test_user',
            'password' => 'secure_password',
            'two_factor_token' => '123456'
        ];

        $result = $this->security->authenticate($credentials);
        
        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->getToken());
        $this->assertTrue($this->security->validateSession($result->getToken()));

        // Test permission checks
        $user = $result->getUser();
        $this->assertTrue($this->security->hasPermission($user, 'content.create'));
        $this->assertTrue($this->security->hasPermission($user, 'template.edit'));

        // Test session management
        $session = $result->getSession();
        $this->assertFalse($session->isExpired());
        $this->assertTrue($session->isValid());
    }

    /**
     * Test content management integration
     */
    public function testContentManagement(): void
    {
        // Authenticate first
        $context = $this->getAuthenticatedContext();

        // Test content creation
        $contentData = [
            'title' => 'Test Content',
            'body' => 'Test content body',
            'status' => 'draft'
        ];

        $content = $this->content->createContent($contentData, $context);
        $this->assertNotNull($content->getId());

        // Test content retrieval
        $retrieved = $this->content->getContent($content->getId(), $context);
        $this->assertEquals($contentData['title'], $retrieved->getTitle());

        // Test content update
        $updateData = ['title' => 'Updated Title'];
        $updated = $this->content->updateContent($content->getId(), $updateData, $context);
        $this->assertEquals('Updated Title', $updated->getTitle());

        // Test content deletion
        $this->assertTrue($this->content->deleteContent($content->getId(), $context));
        $this->expectException(\Exception::class);
        $this->content->getContent($content->getId(), $context);
    }

    /**
     * Test template system integration
     */
    public function testTemplateSystem(): void
    {
        $context = $this->getAuthenticatedContext();

        // Test template creation
        $templateData = [
            'name' => 'test-template',
            'content' => '<h1>{{ title }}</h1><div>{{ content }}</div>'
        ];

        $template = $this->template->saveTemplate(
            $templateData['name'], 
            $templateData['content'],
            $context
        );

        // Test template rendering
        $renderData = [
            'title' => 'Test Title',
            'content' => 'Test Content'
        ];

        $rendered = $this->template->render(
            $templateData['name'],
            $renderData,
            $context
        );

        $this->assertStringContainsString('Test Title', $rendered->getContent());
        $this->assertStringContainsString('Test Content', $rendered->getContent());

        // Test template caching
        $cachedRender = $this->template->render(
            $templateData['name'],
            $renderData,
            $context
        );

        $this->assertEquals($rendered->getContent(), $cachedRender->getContent());
    }

    /**
     * Test infrastructure monitoring and performance
     */
    public function testInfrastructurePerformance(): void
    {
        // Test health monitoring
        $health = $this->infrastructure->monitorHealth();
        $this->assertTrue($health->isHealthy());
        $this->assertLessThan(70, $health->getCpuUsage());
        $this->assertLessThan(80, $health->getMemoryUsage());

        // Test performance optimization
        $optimization = $this->infrastructure->optimizePerformance();
        $this->assertTrue($optimization->isSuccessful());
        $this->assertGreaterThan(0, $optimization->getImprovementPercentage());

        // Test resource management
        $resources = $this->infrastructure->manageResources();
        $this->assertTrue($resources->areWithinLimits());
        $this->assertLessThan(config('infrastructure.limits.memory'), $resources->getMemoryUsage());
    }

    /**
     * Test complete system integration
     */
    public function testCompleteSystemFlow(): void
    {
        // Authenticate
        $context = $this->getAuthenticatedContext();

        // Create template
        $template = $this->template->saveTemplate(
            'article-template',
            '<article><h1>{{ title }}</h1>{{ content }}</article>',
            $context
        );

        // Create content
        $content = $this->content->createContent([
            'title' => 'Integration Test',
            'body' => 'Testing complete system flow',
            'template' => 'article-template'
        ], $context);

        // Render content with template
        $rendered = $this->template->render(
            'article-template',
            [
                'title' => $content->getTitle(),
                'content' => $content->getBody()
            ],
            $context
        );

        // Verify result
        $this->assertStringContainsString('Integration Test', $rendered->getContent());
        $this->assertStringContainsString('Testing complete system flow', $rendered->getContent());

        // Verify system health after operations
        $health = $this->infrastructure->monitorHealth();
        $this->assertTrue($health->isHealthy());
    }

    /**
     * Test security integration across system
     */
    public function testSecurityIntegration(): void
    {
        // Test unauthorized access
        $this->expectException(\UnauthorizedException::class);
        $this->content->createContent(['title' => 'Test'], new SecurityContext());

        // Test rate limiting
        $context = $this->getAuthenticatedContext();
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->content->createContent(['title' => "Test $i"], $context);
            } catch (\RateLimitException $e) {
                $this->assertTrue(true);
                break;
            }
        }

        // Test session expiry
        $expiredContext = $this->getExpiredContext();
        $this->expectException(\SessionExpiredException::class);
        $this->content->getContent(1, $expiredContext);

        // Test SQL injection prevention
        $this->expectException(\ValidationException::class);
        $this->content->createContent([
            'title' => "Test'; DROP TABLE users; --"
        ], $context);
    }

    /**
     * Helper: Get authenticated security context
     */
    private function getAuthenticatedContext(): SecurityContext
    {
        $result = $this->security->authenticate([
            'username' => 'test_user',
            'password' => 'secure_password',
            'two_factor_token' => '123456'
        ]);

        return new SecurityContext($result->getUser(), $result->getToken());
    }

    /**
     * Helper: Get expired security context
     */
    private function getExpiredContext(): SecurityContext
    {
        return new SecurityContext(
            new User(['id' => 1]),
            'expired_token'
        );
    }
}
