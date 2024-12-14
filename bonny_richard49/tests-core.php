// tests/Unit/Security/SecurityManagerTest.php
<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Core\Security\SecurityManager;

class SecurityManagerTest extends TestCase
{
    private SecurityManager $security;

    protected function setUp(): void
    {
        parent::setUp();
        $this->security = app(SecurityManager::class);
    }

    public function test_executes_critical_operation_successfully()
    {
        $result = $this->security->executeCriticalOperation(
            fn() => 'test_result',
            ['action' => 'test.operation']
        );

        $this->assertEquals('test_result', $result);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test.operation',
            'status' => 'success'
        ]);
    }

    public function test_validates_operation_context()
    {
        $this->expectException(SecurityValidationException::class);
        
        $this->security->executeCriticalOperation(
            fn() => true,
            ['invalid_context']
        );
    }
}

// tests/Unit/CMS/ContentManagerTest.php
class ContentManagerTest extends TestCase
{
    private ContentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(ContentManager::class);
    }

    public function test_creates_content_securely()
    {
        $data = [
            'title' => 'Test Title',
            'content' => 'Test Content',
            'status' => 'draft',
            'author_id' => 1,
            'category_id' => 1
        ];

        $content = $this->manager->create($data);

        $this->assertInstanceOf(Content::class, $content);
        $this->assertEquals($data['title'], $content->title);
        $this->assertDatabaseHas('contents', ['id' => $content->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content.create',
            'status' => 'success'
        ]);
    }

    public function test_updates_content_securely()
    {
        $content = Content::factory()->create();
        $updateData = ['title' => 'Updated Title'];

        $updated = $this->manager->update($content->id, $updateData);

        $this->assertEquals('Updated Title', $