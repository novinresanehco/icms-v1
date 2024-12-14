<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationManager;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\{CacheService, ValidationService};

class SecurityTest extends TestCase
{
    private SecurityManager $security;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->security = app(SecurityManager::class);
    }

    public function test_secure_operation_execution()
    {
        $result = $this->security->executeSecureOperation(
            fn() => 'test_data',
            ['user' => $this->createTestUser()]
        );
        $this->assertEquals('test_data', $result);
    }

    public function test_validates_permissions()
    {
        $this->expectException(AuthorizationException::class);
        $this->security->executeSecureOperation(
            fn() => true,
            ['user' => $this->createTestUser(), 'permission' => 'invalid']
        );
    }
}

class AuthenticationTest extends TestCase
{
    private AuthenticationManager $auth;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = app(AuthenticationManager::class);
    }

    public function test_user_authentication()
    {
        $result = $this->auth->authenticate([
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);
        
        $this->assertNotEmpty($result->token);
        $this->assertTrue($this->auth->validateSession($result->token));
    }

    public function test_session_management()
    {
        $result = $this->auth->authenticate([
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);
        
        $this->auth->logout($result->token);
        $this->assertFalse($this->auth->validateSession($result->token));
    }
}

class ContentTest extends TestCase
{
    private ContentManager $content;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->content = app(ContentManager::class);
    }

    public function test_content_creation()
    {
        $user = $this->createTestUser();
        $data = [
            'title' => 'Test Content',
            'body' => 'Test body content',
            'status' => 'draft',
            'category_id' => 1
        ];
        
        $content = $this->content->createContent($data, $user);
        
        $this->assertEquals($data['title'], $content->title);
        $this->assertEquals($data['body'], $content->body);
    }

    public function test_content_update()
    {
        $user = $this->createTestUser();
        $content = $this->content->createContent([
            'title' => 'Original',
            'body' => 'Original body',
            'status' => 'draft',
            'category_id' => 1
        ], $user);
        
        $updated = $this->content->updateContent($content->id, [
            'title' => 'Updated'
        ], $user);
        
        $this->assertEquals('Updated', $updated->title);
    }
}

class TemplateTest extends TestCase
{
    private TemplateManager $template;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->template = app(TemplateManager::class);
    }

    public function test_template_rendering()
    {
        $result = $this->template->render('test-template', [
            'title' => 'Test Title',
            'content' => 'Test content'
        ]);
        
        $this->assertStringContainsString('Test Title', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_security_measures()
    {
        $result = $this->template->render('test-template', [
            'content' => '<script>alert("xss")</script>'
        ]);
        
        $this->assertStringNotContainsString('<script>', $result);
    }
}

class CacheTest extends TestCase
{
    private CacheService $cache;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = app(CacheService::class);
    }

    public function test_cache_operations()
    {
        $key = 'test_key';
        $value = 'test_value';
        
        $result = $this->cache->remember($key, fn() => $value);
        $this->assertEquals($value, $result);
        
        $this->cache->forget($key);
        $newResult = $this->cache->remember($key, fn() => 'new_value');
        $this->assertEquals('new_value', $newResult);
    }
}

class ValidationTest extends TestCase
{
    private ValidationService $validator;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(ValidationService::class);
    }

    public function test_validation_rules()
    {
        $data = ['email' => 'test@example.com'];
        $rules = ['email' => 'required|email'];
        
        $validated = $this->validator->validate($data, $rules);
        $this->assertEquals($data, $validated);
        
        $this->expectException(ValidationException::class);
        $this->validator->validate(['email' => 'invalid'], $rules);
    }
}

trait TestHelpers
{
    protected function createTestUser(): object
    {
        return DB::table('users')->insertGetId([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
