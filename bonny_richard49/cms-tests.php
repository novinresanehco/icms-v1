<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Security\SecurityContext;

class CMSIntegrationTest extends TestCase
{
    protected SecurityContext $context;
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test context
        $this->context = new SecurityContext([
            'user_id' => 1,
            'role' => 'admin',
            'permissions' => ['*']
        ]);
        
        // Start transaction
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction
        DB::rollBack();
        
        // Clear cache
        Cache::flush();
        
        parent::tearDown();
    }

    public function testContentManagement()
    {
        $contentManager = app(ContentManager::class);

        // Create content
        $content = $contentManager->create([
            'title' => 'Test Content',
            'body' => 'Test content body',
            'type' => 'article',
            'status' => 'draft'
        ], $this->context);

        $this->assertNotNull($content);
        $this->assertEquals('Test Content', $content->title);
        
        // Update content
        $updated = $contentManager->update($content->id, [
            'title' => 'Updated Title'
        ], $this->context);

        $this->assertEquals('Updated Title', $updated->title);
        
        // Publish content
        $published = $contentManager->publish($content->id, $this->context);
        $this->assertTrue($published);
        
        // Delete content
        $deleted = $contentManager->delete($content->id, $this->context);
        $this->assertTrue($deleted);
    }

    public function testMediaManagement()
    {
        $mediaManager = app(MediaManager::class);

        // Create test file
        $file = UploadedFile::fake()->image('test.jpg');

        // Upload media
        $media = $mediaManager->upload($file, [
            'title' => 'Test Image',
            'alt_text' => 'Test image description'
        ], $this->context);

        $this->assertNotNull($media);
        $this->assertEquals('Test Image', $media->title);
        
        // Generate thumbnails
        $thumbnails = $mediaManager->generateThumbnails($media->id, $this->context);
        $this->assertNotEmpty($thumbnails);
        
        // Delete media
        $deleted = $mediaManager->delete($media->id, $this->context);
        $this->assertTrue($deleted);
    }

    public function testUserAuthentication()
    {
        $authManager = app(AuthManager::class);

        // Authenticate user
        $result = $authManager->authenticate([
            'email' => 'test@example.com',
            'password' => 'password123'
        ], $this->context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->token);
        
        // Validate access
        $access = $authManager->validateAccess(
            $result->token,
            ['content.view'],
            $this->context
        );
        
        $this->assertTrue($access);
        
        // Revoke token
        $revoked = $authManager->revokeToken($result->token, $this->context);
        $this->assertTrue($revoked);
    }

    public function testTemplateSystem()
    {
        $templateManager = app(TemplateManager::class);

        // Create template
        $template = $templateManager->create([
            'name' => 'test-template',
            'content' => '<h1>{{ title }}</h1><div>{{ content }}</div>',
            'schema' => [
                'title' => 'required|string',
                'content' => 'required|string'
            ]
        ], $this->context);

        $this->assertNotNull($template);
        
        // Render template
        $rendered = $templateManager->render('test-template', [
            'title' => 'Test Title',
            'content' => 'Test content'
        ], $this->context);

        $this->assertStringContainsString('Test Title', $rendered);
        
        // Delete template
        $deleted = $templateManager->delete($template->id, $this->context);
        $this->assertTrue($deleted);
    }

    public function testPluginSystem()
    {
        $pluginManager = app(PluginManager::class);

        // Install plugin
        $plugin = $pluginManager->install('test-plugin', $this->context);
        $this->assertNotNull($plugin);
        
        // Enable plugin
        $enabled = $pluginManager->enable($plugin->id, $this->context);
        $this->assertTrue($enabled);
        
        // Disable plugin
        $disabled = $pluginManager->disable($plugin->id, $this->context);
        $this->assertTrue($disabled);
        
        // Uninstall plugin
        $uninstalled = $pluginManager->uninstall($plugin->id, $this->context);
        $this->assertTrue($uninstalled);
    }

    public function testWorkflowSystem()
    {
        $workflowManager = app(WorkflowManager::class);

        // Create content version
        $version = $workflowManager->createVersion([
            'content_id' => 1,
            'content' => 'Test content',
            'metadata' => ['author' => 'Test User']
        ], $this->context);

        $this->assertNotNull($version);
        
        // Transition state
        $state = $workflowManager->transition(
            $version->id,
            'submit_for_review',
            ['reviewer' => 'Test Reviewer'],
            $this->context
        );

        $this->assertEquals('pending_review', $state->state);
        
        // Get history
        $history = $workflowManager->getHistory($version->id, $this->context);
        $this->assertNotEmpty($history);
    }

    public function testSearchSystem()
    {
        $searchManager = app(SearchManager::class);

        // Index content
        $indexed = $searchManager->indexContent([
            'id' => 1,
            'title' => 'Test Content',
            'content' => 'Test content body',
            'type' => 'article'
        ], $this->context);

        $this->assertTrue($indexed);
        
        // Search content
        $results = $searchManager->search([
            'term' => 'test',
            'type' => 'article'
        ], $this->context);

        $this->assertNotEmpty($results);
        
        // Remove from index
        $removed = $searchManager->removeFromIndex(1, $this->context);
        $this->assertTrue($removed);
    }

    public function testAnalyticsSystem()
    {
        $analyticsManager = app(AnalyticsManager::class);

        // Track event
        $event = new AnalyticsEvent([
            'type' => 'page_view',
            'category' => 'content',
            'content_id' => 1,
            'user_id' => 1,
            'metadata' => ['ip' => '127.0.0.1']
        ]);

        $analyticsManager->trackEvent($event, $this->context);
        
        // Generate report
        $report = $analyticsManager->generateReport([
            'start_date' => now()->subDay(),
            'end_date' => now(),
            'metrics' => ['page_views', 'unique_visitors'],
            'dimensions' => ['content_id']
        ], $this->context);

        $this->assertNotNull($report);
        $this->assertNotEmpty($report->getData());
    }

    public function testMonitoringSystem()
    {
        $monitorManager = app(MonitoringManager::class);

        // Check system status
        $status = $monitorManager->checkSystem($this->context);
        
        $this->assertTrue($status->isHealthy());
        $this->assertFalse($status->hasCriticalAlerts());
        
        // Get metrics
        $metrics = $monitorManager->getMetrics('system', $this->context);
        $this->assertNotEmpty($metrics);
        
        // Get alerts
        $alerts = $monitorManager->getAlerts([
            'severity' => 'critical'
        ], $this->context);
        
        $this->assertEmpty($alerts);
    }

    public function testDeploymentSystem()
    {
        $deployManager = app(DeploymentManager::class);

        // Create deployment package
        $package = new DeploymentPackage([
            'version' => '1.0.0',
            'type' => 'feature',
            'migrations' => [],
            'requirements' => [
                'php' => '>=8.0',
                'mysql' => '>=5.7'
            ]
        ]);

        // Deploy package
        $result = $deployManager->deploy($package, $this->context);
        
        $this->assertTrue($result->success);
        $this->assertEquals('completed', $result->state->status);
        
        // Get deployment status
        $status = $deployManager->getStatus($result->state->id);
        $this->assertEquals('completed', $status->status);
    }
}
