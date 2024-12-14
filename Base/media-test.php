<?php

namespace Tests\Feature;

use App\Core\Models\Media;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('public');
        
        $this->user = User::factory()->create();
        $this->user->givePermission('media.create');
    }

    public function test_can_upload_media(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media', [
                'file' => $file,
                'type' => 'image'
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'type',
                    'url',
                    'size',
                    'metadata'
                ]
            ]);

        Storage::disk('public')->assertExists('media/image/' . $file->hashName());
    }

    public function test_can_delete_media(): void
    {
        $this->user->givePermission('media.delete');
        
        $media = Media::factory()->create();
        Storage::disk('public')->put($media->path, 'test content');

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/media/{$media->id}");

        $response->assertNoContent();
        Storage::disk('public')->assertMissing($media->path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}
