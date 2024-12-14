<?php

namespace Tests\Unit\Core\Media;

use PHPUnit\Framework\TestCase;
use App\Core\Media\MediaStorageService;
use App\Core\Exception\{
    AccessDeniedException,
    NotFoundException,
    ValidationException
};

/**
 * Critical tests for media storage service
 */
class MediaStorageServiceTest extends TestCase
{
    private MediaStorageService $service;
    private $security;
    private $monitor;
    private $cache;
    private $store;
    private $validator;

    protected function setUp(): void
    {
        $this->security = $this->createMock(SecurityManagerInterface::class);
        $this->monitor = $this->createMock(OperationMonitorInterface::class);
        $this->cache = $this->createMock(CacheManagerInterface::class);
        $this->store = $this->createMock(StorageManagerInterface::class);
        $this->validator = $this->createMock(ValidationServiceInterface::class);

        $this->service = new MediaStorageService(
            $this->security,
            $this->monitor,
            $this->cache,
            $this->store,
            $this->validator
        );
    }

    public function testRetrieveMediaSuccess(): void
    {
        // Arrange
        $fileId = 'test-file-1';
        $userId = 'user-1';
        $expectedData = ['content' => 'test'];

        $this->security->expects($this->once())
            ->method('validateAccess')
            ->with($userId, 'media:retrieve', $fileId);

        $this->store->expects($this->once())
            ->method('retrieveSecure')
            ->with($fileId, $userId)
            ->willReturn($expectedData);

        $this->validator->expects($this->once())
            ->method('validateMediaData')
            ->with($expectedData)
            ->willReturn($expectedData);

        // Act
        $result = $this->service->retrieveMedia($fileId, $userId);

        // Assert
        $this->assertEquals($expectedData, $result);
    }

    public function testRetrieveMediaAccessDenied(): void
    {
        // Arrange
        $fileId = 'test-file-1';
        $userId = 'user-1';

        $this->security->expects($this->once())
            ->method('validateAccess')
            ->with($userId, 'media:retrieve', $fileId)
            ->willThrowException(new AccessDeniedException());

        // Assert & Act
        $this->expectException(AccessDeniedException::class);
        $this->service->retrieveMedia($fileId, $userId);
    }

    public function testRetrieveMediaNotFound(): void
    {
        // Arrange
        $fileId = 'test-file-1';
        $userId = 'user-1';

        $this->security->expects($this->once())
            ->method('validateAccess')
            ->with($userId, 'media:retrieve', $fileId);

        $this->store->expects($this->once())
            ->method('retrieveSecure')
            ->willReturn(null);

        // Assert & Act  
        $this->expectException(NotFoundException::class);
        $this->service->retrieveMedia($fileId, $userId);
    }
}
