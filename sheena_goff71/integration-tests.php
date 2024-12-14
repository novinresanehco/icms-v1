<?php

namespace Tests\Integration;

use Tests\TestCase;
use App\Core\Auth\AuthenticationSystem;
use App\Core\CMS\ContentManagementSystem;
use App\Core\Template\TemplateSystem;
use App\Core\Infrastructure\InfrastructureManager;

class CriticalSystemIntegrationTest extends TestCase
{
    protected AuthenticationSystem $auth;
    protected ContentManagementSystem $cms;
    protected TemplateSystem $template;
    protected InfrastructureManager $infrastructure;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize core systems
        $this->auth = app(AuthenticationSystem::class);
        $this->cms = app(ContentManagementSystem::class);
        $this->template = app(TemplateSystem::class);
        $this->infrastructure = app(InfrastructureManager::class);
        
        // Ensure clean test state
        $this->artisan('migrate:fresh');
        $this->seed(TestDataSeeder::class);
    }

    /** @test */
    public function complete_user_authentication_flow(): void
    {
        // Test user login
        $credentials = ['email' => 'test@example.com', 'password' => 'secret'];
        $authResult = $this->auth->authenticate($credentials);
        
        $this->assertTrue($authResult->isSuccessful());
        $this->assertNotNull($authResult->getToken());
        
        // Verify session
        $sessionResult = $this->auth->validateSession($authResult->getToken());
        $this->assertTrue($sessionResult->isValid());
        
        // Test permission check
        $this->assertTrue($sessionResult->hasPermission('content.create'));
        
        // Test logout
        $this->auth->logout($authResult->getToken());
        
        // Verify session invalidation
        $this->expectException(InvalidSessionException::class);
        $this->auth->validateSession($authResult->getToken());
    }

    /** @test */
    public function complete_content_management_flow(): void
    {
        // Login and get token
        $token = $this->getAuthToken();
        
        // Create content
        $content = $this->cms->createContent([
            'title' => 'Test Content',
            'body' => 'Test body content',
            'status' => 'draft'
        ], [], $token);
        
        $this->assertNotNull($content->getId());
        
        // Update content
        $updated = $this->cms->updateContent($content->getId(), [
            'title' => 'Updated Title',
            'body' => 'Updated body content'
        ], [], $token);
        
        $this->assertEquals('Updated Title', $updated->getTitle());
        
        // Publish content
        $published = $this->cms->publishContent($content->getId(), $token);
        $this->assertEquals('published', $published->getStatus());
        
        // Verify content in cache
        $cached = $this->infrastructure->cache()->get("content:{$content->getId()}");
        $this->assertNotNull($cached);
    }

    /** @test */
    public function complete_template_rendering_flow(): void
    {
        // Register template
        $template = $this->template->registerTemplate([
            'name' => 'Test Template',
            'path' => resource_path('templates/test.blade.php'),
            'schema' => ['title', 'body']
        ]);
        
        // Create content
        $content = $this->cms->createContent([
            'title' => 'Test Content',
            'body' => 'Test body'
        ], []);
        
        // Render content with template
        $rendered = $this->template->renderContent(
            $content->getId(),
            $template->getId()
        );
        
        $this->assertStringContainsString('Test Content', $rendered->getHtml());
        $this->assertStringContainsString('Test body', $rendered->getHtml());
    }

    /** @test */
    public function infrastructure_performance_and_security(): void
    {
        // Test rate limiting
        $this->expectException(RateLimitExceededException::class);
        for ($i = 0; $i <= config('infrastructure.rate_limit'); $i++) {
            $this->infrastructure->handleRequest(
                $this->createTestRequest()
            );
        }
        
        // Test cache encryption
        $sensitive = ['key' => 'sensitive_data'];
        $this->infrastructure->cache()->set('test_key', $sensitive);
        
        $encrypted = Redis::connection()->get(
            $this->infrastructure->cache()->generateSecureCacheKey('test_key')
        );
        
        // Verify data is encrypted
        $this->assertNotEquals(
            json_encode($sensitive),
            $encrypted
        );
        
        // Verify decryption works
        $decrypted = $this->infrastructure->cache()->get('test_key');
        $this->assertEquals($sensitive, $decrypted);
        
        // Test health checking
        $health = $this->infrastructure->healthChecker()->verifySystemHealth();
        $this->assertTrue($health->isHealthy());
        $this->assertTrue($health->database);
        $this->assertTrue($health->cache);
    }

    /** @test */
    public function security_integration_verification(): void
    {
        // Test XSS prevention
        $maliciousContent = '<script>alert("xss")</script>Test';
        $content = $this->cms->createContent([
            'title' => 'Test',
            'body' => $maliciousContent
        ], []);
        
        $rendered = $this->template->renderContent($content->getId());
        $this->assertStringNotContainsString(
            '<script>',
            $rendered->getHtml()
        );
        
        // Test SQL injection prevention
        $this->expectException(ValidationException::class);
        $this->cms->getContent("1; DROP TABLE users;");
        
        // Test CSRF protection
        $this->expectException(CsrfException::class);
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post('/api/content', ['title' => 'Test']);
    }

    protected function getAuthToken(): string
    {
        $result = $this->auth->authenticate([
            'email' => 'test@example.com',
            'password' => 'secret'
        ]);
        return $result->getToken();
    }

    protected function createTestRequest(): Request
    {
        return Request::create('/api/test', 'GET');
    }
}
