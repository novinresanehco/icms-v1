<?php

namespace Tests\Core;

use Tests\TestCase;
use App\Core\Security\CoreSecurityManager;
use App\Core\Auth\AuthenticationSystem;
use App\Core\Content\ContentManager;

class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->security = app(CoreSecurityManager::class);
    }

    public function testSecureOperationValidation()
    {
        $this->expectException(SecurityException::class);
        
        $this->security->executeSecureOperation(
            fn() => true,
            ['permission' => 'invalid']
        );
    }

    public function testAuthenticationFlow()
    {
        $auth = app(AuthenticationSystem::class);
        
        $result = $auth->authenticate([
            'username' => 'test_admin',
            'password' => 'test_password'
        ]);

        $this->assertNotNull($result->token);
        $this->assertTrue($this->security->validateToken($result->token));
    }

    public function testPermissionChecking()
    {
        $user = $this->createTestUser(['admin']);
        $this->actingAs($user);

        $this->assertTrue(
            $this->security->hasPermission('content.view')
        );
    }
}

class ContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->content = app(ContentManager::class);
        $this->security = app(CoreSecurityManager::class);
    }

    public function testContentCreation()
    {
        $user = $this->createTestUser(['editor']);
        $this->actingAs($user);

        $data = [
            'title' => 'Test Content',
            'content' => 'Test content body',
            'status' => 'draft'
        ];

        $content = $this->content->createContent($data);

        $this->assertNotNull($content->id);
        $this->assertEquals($data['title'], $content->title);
    }

    public function testContentUpdate()
    {
        $user = $this->createTestUser(['editor']);
        $this->actingAs($user);

        $content = $this->createTestContent();
        
        $updated = $this->content->updateContent($content->id, [
            'title' => 'Updated Title'
        ]);

        $this->assertEquals('Updated Title', $updated->title);
    }

    public function testUnauthorizedAccess()
    {
        $user = $this->createTestUser(['viewer']);
        $this->actingAs($user);

        $this->expectException(SecurityException::class);
        
        $this->content->createContent([
            'title' => 'Test'
        ]);
    }
}

class ApiTest extends TestCase
{
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->getTestToken();
    }

    public function testContentApi()
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/content', [
                'title' => 'API Test',
                'content' => 'Test content',
                'status' => 'draft'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'title' => 'API Test'
                ]
            ]);
    }

    public function testContentValidation()
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/content', []);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Validation failed'
            ]);
    }

    public function testUnauthorizedApi()
    {
        $response = $this->postJson('/api/content', [
            'title' => 'Test'
        ]);

        $response->assertStatus(401);
    }
}

trait TestHelpers
{
    protected function createTestUser(array $roles): User
    {
        return factory(User::class)->create()->attachRoles($roles);
    }

    protected function createTestContent(): Content
    {
        return factory(Content::class)->create();
    }

    protected function getTestToken(): string
    {
        $auth = app(AuthenticationSystem::class);
        $result = $auth->authenticate([
            'username' => 'test_admin',
            'password' => 'test_password'
        ]);
        return $result->token;
    }
}

class DatabaseFactories
{
    protected function userFactory(): array
    {
        return [
            'username' => $this->faker->userName,
            'password' => Hash::make('password'),
            'active' => true
        ];
    }

    protected function contentFactory(): array
    {
        return [
            'title' => $this->faker->sentence,
            'content' => $this->faker->paragraphs(3, true),
            'status' => $this->faker->randomElement(['draft', 'published']),
            'meta' => json_encode([]),
            'author_id' => fn() => factory(User::class)
        ];
    }
}
