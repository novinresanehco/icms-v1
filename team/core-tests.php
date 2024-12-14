<?php

namespace Tests\Core;

use Tests\TestCase;
use App\Core\{
    CoreSystem,
    Security\SecurityManager,
    Auth\AuthenticationManager,
    CMS\ContentManager,
    Template\TemplateManager
};

class CriticalSystemTest extends TestCase
{
    private CoreSystem $system;
    private SecurityManager $security;
    private AuthenticationManager $auth;
    private ContentManager $cms;
    private TemplateManager $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->system = app(CoreSystem::class);
        $this->security = app(SecurityManager::class);
        $this->auth = app(AuthenticationManager::class);
        $this->cms = app(ContentManager::class);
        $this->template = app(TemplateManager::class);
    }

    public function testAuthenticationFlow(): void
    {
        $credentials = ['email' => 'test@test.com', 'password' => 'password'];
        
        $result = $this->auth->authenticate($credentials);
        $this->assertNotNull($result->token);
        
        $valid = $this->auth->validateToken($result->token);
        $this->assertTrue($valid);
        
        $this->auth->logout($result->token);
        $valid = $this->auth->validateToken($result->token);
        $this->assertFalse($valid);
    }

    public function testContentOperations(): void
    {
        $data = [
            'title' => 'Test Content',
            'content' => 'Test content body',
            'status' => 'draft'
        ];

        $content = $this->cms->store($data);
        $this->assertNotNull($content->id);

        $found = $this->cms->find($content->id);
        $this->assertEquals($data['title'], $found->title);

        $updated = $this->cms->update($content->id, ['title' => 'Updated']);
        $this->assertEquals('Updated', $updated->title);

        $deleted = $this->cms->delete($content->id);
        $this->assertTrue($deleted);
    }

    public function testTemplateRendering(): void
    {
        $data = ['content' => 'Test content'];
        $rendered = $this->template->render('default', $data);
        $this->assertStringContainsString('Test content', $rendered);
    }

    public function testSecurityValidation(): void
    {
        $this->expectException(\App\Core\Exceptions\SecurityException::class);
        $this->security->executeCriticalOperation(
            fn() => throw new \Exception('Test'),
            ['action' => 'test']
        );
    }

    public function testCacheOperations(): void
    {
        $key = 'test_key';
        $value = 'test_value';
        
        $this->system->cache->remember($key, $value);
        $cached = $this->system->cache->get($key);
        $this->assertEquals($value, $cached);
        
        $this->system->cache->invalidate($key);
        $cached = $this->system->cache->get($key);
        $this->assertNull($cached);
    }

    public function testDatabaseTransactions(): void
    {
        $this->expectException(\App\Core\Exceptions\DatabaseException::class);
        DB::transaction(function() {
            throw new \Exception('Test rollback');
        });
    }

    public function testAuditLogging(): void
    {
        $event = 'test_event';
        $context = ['data' => 'test'];
        
        $this->system->audit->log($event, $context);
        $logs = $this->system->audit->getLogs($event);
        
        $this->assertCount(1, $logs);
        $this->assertEquals($context, $logs[0]['context']);
    }

    public function testRateLimiting(): void
    {
        $key = 'rate_limit_test';
        $limit = 3;
        
        for ($i = 0; $i < $limit; $i++) {
            $allowed = $this->system->rateLimit->attempt($key);
            $this->assertTrue($allowed);
        }
        
        $denied = $this->system->rateLimit->attempt($key);
        $this->assertFalse($denied);
    }

    public function testMetricsCollection(): void
    {
        $metrics = [
            'response_time' => 100,
            'memory_usage' => 1024,
            'cpu_usage' => 50
        ];
        
        $this->system->metrics->record($metrics);
        $collected = $this->system->metrics->get();
        
        $this->assertArrayHasKey('response_time', $collected);
        $this->assertEquals(100, $collected['response_time']);
    }

    public function testErrorHandling(): void
    {
        $this->expectException(\App\Core\Exceptions\SystemException::class);
        $this->system->handleError(new \Exception('Test error'));
    }
}
