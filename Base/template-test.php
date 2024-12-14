<?php

namespace Tests\Feature;

use App\Core\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_templates(): void
    {
        Template::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/templates');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_template(): void
    {
        $template = Template::factory()->make();

        $response = $this->actingAs($this->user)
            ->postJson('/api/templates', $template->toArray());

        $response->assertCreated()
            ->assertJsonFragment(['name' => $template->name]);
    }

    public function test_can_update_template(): void
    {
        $template = Template::factory()->create();
        $newData = ['name' => 'Updated Template'];

        $response = $this->actingAs($this->user)
            ->putJson("/api/templates/{$template->id}", $newData);

        $response->assertOk()
            ->assertJsonFragment($newData);
    }

    public function test_can_delete_template(): void
    {
        $template = Template::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/templates/{$template->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted($template);
    }

    public function test_can_compile_template(): void
    {
        $template = Template::factory()->create([
            'content' => 'Hello {{ $name }}'
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/templates/{$template->id}/compile", [
                'variables' => ['name' => 'World']
            ]);

        $response->assertOk()
            ->assertJsonFragment(['compiled' => 'Hello World']);
    }
}
