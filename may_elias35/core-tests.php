<?php

namespace Tests\Core;

use Tests\TestCase;
use App\Core\{Security, Auth, CMS};
use Illuminate\Foundation\Testing\RefreshDatabase;

class SecurityTest extends TestCase
{
    public function testAuthenticationBlocks()
    {
        $response = $this->getJson('/api/content');
        $response->assertStatus(401);

        $response = $this->postJson('/api/content', [
            'title' => 'Test',
            'content' => 'Content'
        ]);
        $response->assertStatus(401);
    }

    public function testInvalidLogin()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'fake@example.com',
            'password' => 'wrong'
        ]);
        $response->assertStatus(401);
    }

    public function testRateLimit()
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'password'
            ]);
        }
        
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password'
        ]);
        $response->assertStatus(429);
    }
}

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function testSuccessfulLogin()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password')
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password'
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'user_id']);
    }

    public function test2FAVerification()
    {
        $response = $this->postJson('/api/auth/verify', [
            'user_id' => 1,
            'token' => '123456'
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token']);
    }

    public function testSessionExpiry()
    {
        $token = 'expired_token';
        $response = $this->getJson('/api/content', [
            'Authorization' => "Bearer {$token}"
        ]);
        $response->assertStatus(401);
    }
}

class ContentTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->token = $this->getValidToken();
    }

    public function testContentCreation()
    {
        $response = $this->withToken($this->token)->postJson('/api/content', [
            'title' => 'Test Content',
            'content' => 'Test Body',
            'status' => 'draft'
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'title', 'content']);
    }

    public function testContentValidation()
    {
        $response = $this->withToken($this->token)->postJson('/api/content', [
            'title' => '',
            'content' => ''
        ]);

        $response->assertStatus(422);
    }

    public function testUnauthorizedAccess()
    {
        $this->user->role = 'user';
        $this->user->save();

        $response = $this->withToken($this->token)->deleteJson('/api/content/1');
        $response->assertStatus(403);
    }
}

class SystemTest extends TestCase
{
    public function testErrorHandling()
    {
        $response = $this->getJson('/api/nonexistent');
        $response->assertStatus(404);

        $response = $this->getJson('/api/content/9999');
        $response->assertStatus(404);
    }

    public function testCacheSystem()
    {
        $content = Content::factory()->create();
        
        $firstResponse = $this->getJson("/api/content/{$content->id}");
        $firstResponse->assertStatus(200);
        
        Content::destroy($content->id);
        
        $cachedResponse = $this->getJson("/api/content/{$content->id}");
        $cachedResponse->assertStatus(200)
            ->assertJson($firstResponse->json());
    }

    public function testSecurityHeaders()
    {
        $response = $this->get('/');
        
        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-XSS-Protection', '1; mode=block')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
