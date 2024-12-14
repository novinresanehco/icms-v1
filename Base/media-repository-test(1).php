<?php

namespace Tests\Unit\Repositories;

use App\Core\Models\Media;
use App\Core\Repositories\MediaRepository;
use App\Core\Exceptions\MediaNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaRepositoryTest extends TestCase
{
    use RefreshDatabase;
    
    private MediaRepository $repository;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MediaRepository(new Media());
    }
    
    public function test_can_create_media(): void
    {
        $data = [
            'name' => 'test-image.jpg',
            'type' => 'image',
            'path' => 'media/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'metadata' => ['width' => 800, 'height' => 600]
        ];
        
        $media = $this->repository->store($data);
        
        $this->assertInstanceOf(Media::class, $media);
        $this->assertEquals('test-image.jpg', $media->name);
    }
    
    public function test_throws_exception_when_media_not_found(): void
    {
        $this->expectException(MediaNotFoundException::class);
        $this->repository->findById(999);
    }
}
