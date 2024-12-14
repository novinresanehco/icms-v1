<?php

namespace Tests\Unit;

use App\Core\System\StorageService;
use App\Core\Exceptions\StorageException;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class StorageServiceTest extends TestCase
{
    private StorageService $service;
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new StorageService($this->logger);
        
        LaravelStorage::fake('local');
    }

    public function test_put_and_get_file(): void
    {
        $path = 'test.txt';
        $content = 'Test content';

        $result = $this->service->put($path, $content);
        $this->assertTrue($result);

        $retrieved = $this->service->get($path);
        $this->assertEquals($content, $retrieved);
    }

    public function test_delete_file(): void
    {
        $path = 'test.txt';
        $content = 'Test content';

        $this->service->put($path, $content);
        $this->assertTrue($this->service->exists($path));

        $result = $this->service->delete($path);
        $this->assertTrue($result);
        $this->assertFalse($this->service->exists($path));
    }

    public function test_file_size(): void
    {
        $path = 'test.txt';
        $content = 'Test content';

        $this->service->put($path, $content);
        
        $size = $this->service->size($path);
        $this->assertEquals(strlen($content), $size);
    }

    public function test_last_modified(): void
    {
        $path = 'test.txt';
        $content = 'Test content';

        $this->service->put($path, $content);
        
        $time = $this->service->lastModified($path);
        $this->assertIsInt($time);
        $this->assertTrue($time > 0);
    }

    public function test_get_non_existent_file(): void
    {
        $this->expectException(StorageException::class);
        $this->service->get('non-existent.txt');
    }

    public function test_verify_file_content(): void
    {
        $path = 'test.txt';
        $content = 'Test content';

        $this->service->put($path, $content);
        
        LaravelStorage::disk('local')->put($path, 'Modified content');
        
        $this->expectException(StorageException::class);
        $this->service->get($path);
    }

    public function test_retry_mechanism(): void
    {
        LaravelStorage::shouldReceive('get')
            ->once()
            ->andThrow(new \Exception('Storage error'))
            ->shouldReceive('get')
            ->once()
            ->andReturn('Test content');

        $content = $this->service->get('test.txt');
        $this->assertEquals('Test content', $content);
    }
}
