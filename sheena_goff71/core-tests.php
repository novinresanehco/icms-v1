<?php

namespace Tests\Core;

use Tests\TestCase;
use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationService;
use App\Core\CMS\ContentManager;
use App\Core\Exceptions\SecurityException;

class CoreSystemTest extends TestCase
{
    protected SecurityManager $security;
    protected AuthenticationService $auth;
    protected ContentManager $content;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->security = $this->app->make(SecurityManager::class);
        $this->auth = $this->app->make(AuthenticationService::class);
        $this->content = $this->app->make(ContentManager::class);
    }

    public function test_core_security_validation(): void
    {
        $this->expectException(SecurityException::class);
        
        $this->security->validateAccess('invalid.permission');
    }

    public function test_critical_authentication(): void
    {
        $credentials = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'mfa_token' => '123456'
        ];

        $user = $this->auth->authenticate($credentials);
        
        $this->assertNotNull($user);
        $this->assertTrue($this->auth->validateSession());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.login_success',
            'user_id' => $user->id
        ]);
    }

    public function test_content_management(): void
    {
        $data = [
            'title' => 'Test Content',
            'body' => 'Test body content',
            'status' => 'draft'
        ];

        $content = $this->content->store($data);
        
        $this->assertNotNull($content);
        $this->assertEquals($data['title'], $content->title);
        $this->assertTrue($this->security->validateContentAccess($content));
    }

    public function test_system_integrity(): void
    {
        $critical_tables = ['users', 'contents', 'audit_logs'];
        
        foreach ($critical_tables as $table) {
            $this->assertDatabaseHas($table, [], 'Critical table missing');
        }
        
        $this->assertTrue($this->security->verifySystemIntegrity());
    }

    public function test_security_monitoring(): void
    {
        $monitor = $this->security->getMonitor();
        
        $this->assertTrue($monitor->isActive());
        $this->assertFalse($monitor->hasHighSeverityAlerts());
        $this->assertTrue($monitor->validateSecurityMetrics());
    }

    public function test_critical_operations(): void
    {
        $this->security->beginCriticalOperation();
        
        try {
            $result = $this->content->publish(1);
            $this->assertTrue($result);
            
            $this->security->commitCriticalOperation();
        } catch (\Exception $e) {
            $this->security->rollbackCriticalOperation();
            throw $e;
        }
    }

    public function test_backup_integrity(): void
    {
        $backup = $this->app->make('backup.service');
        
        $backupId = $backup->createBackup(true);
        
        $this->assertNotNull($backupId);
        $this->assertTrue($backup->verifyBackup($backupId));
    }

    public function test_audit_logging(): void
    {
        $audit = $this->app->make('audit.service');
        
        $audit->log('test.event', ['data' => 'test']);
        
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'test.event'
        ]);
    }

    public function test_template_system(): void
    {
        $template = $this->app->make('template.manager');
        
        $result = $template->render('test', ['data' => 'test']);
        
        $this->assertNotNull($result);
        $this->assertTrue($template->validateTemplate('test'));
    }

    public function test_infrastructure_health(): void
    {
        $infrastructure = $this->app->make('core.infrastructure');
        
        $this->assertTrue($infrastructure->isHealthy());
        $this->assertFalse($infrastructure->hasErrors());
        $this->assertTrue($infrastructure->validateResources());
    }
}
