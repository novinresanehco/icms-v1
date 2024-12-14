<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Core\Repositories\BaseRepository;
use App\Repositories\{
    ContentRepository,
    CommentRepository,
    RevisionRepository,
    PermissionRepository
};
use Illuminate\Foundation\Testing\RefreshDatabase;

class RepositoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected BaseRepository $repository;
    protected string $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app($this->getRepositoryClass());
        $this->model = $this->repository->model();
    }

    protected function createModel(array $attributes = []): mixed
    {
        return $this->model::factory()->create($attributes);
    }

    abstract protected function getRepositoryClass(): string;
}

class ContentRepositoryTest extends RepositoryTestCase
{
    protected function getRepositoryClass(): string
    {
        return ContentRepository::class;
    }

    public function test_it_can_create_content_with_meta()
    {
        $attributes = [
            'title' => 'Test Content',
            'slug' => 'test-content',
            'content' => 'Test content body'
        ];

        $meta = [
            ['key' => 'description', 'value' => 'Test description'],
            ['key' => 'keywords', 'value' => 'test,content']
        ];

        $content = $this->repository->createWithMeta($attributes, $meta);

        $this->assertDatabaseHas('contents', $attributes);
        $this->assertCount(2, $content->meta);
    }

    public function test_it_can_update_content_with_meta()
    {
        $content = $this->createModel();

        $attributes = [
            'title' => 'Updated Title',
            'content' => 'Updated content'
        ];

        $meta = [
            ['key' => 'description', 'value' => 'Updated description']
        ];

        $updated = $this->repository->updateWithMeta($content->id, $attributes, $meta);

        $this->assertDatabaseHas('contents', $attributes);
        $this->assertCount(1, $updated->meta);
    }
}

class CommentRepositoryTest extends RepositoryTestCase
{
    protected function getRepositoryClass(): string
    {
        return CommentRepository::class;
    }

    public function test_it_can_find_approved_comments_for_content()
    {
        $content = Content::factory()->create();
        
        Comment::factory()->count(3)->create([
            'content_id' => $content->id,
            'approved' => true
        ]);

        Comment::factory()->create([
            'content_id' => $content->id,
            'approved' => false
        ]);

        $comments = $this->repository->findForContent($content->id);

        $this->assertCount(3, $comments);
    }

    public function test_it_validates_comment_data()
    {
        $data = [
            'content' => '',
            'author_email' => 'invalid-email'
        ];

        $this->assertFalse($this->repository->validate($data));
        $this->assertNotEmpty($this->repository->getErrors());
    }
}

class RevisionRepositoryTest extends RepositoryTestCase
{
    protected function getRepositoryClass(): string
    {
        return RevisionRepository::class;
    }

    public function test_it_can_create_revision()
    {
        $content = Content::factory()->create();
        $data = ['title' => 'Updated Title'];
        
        $revision = $this->repository->createRevision(
            $content->id,
            $data,
            'Test revision'
        );

        $this->assertDatabaseHas('revisions', [
            'content_id' => $content->id,
            'comment' => 'Test revision'
        ]);
        
        $this->assertEquals($data, $revision->data);
    }

    public function test_it_can_compare_versions()
    {
        $content = Content::factory()->create();
        
        $revision1 = $this->repository->createRevision(
            $content->id,
            ['title' => 'First Title']
        );

        $revision2 = $this->repository->createRevision(
            $content->id,
            ['title' => 'Second Title']
        );

        $diff = $this->repository->compareVersions($revision1->id, $revision2->id);
        
        $this->assertArrayHasKey('title', $diff);
        $this->assertEquals('Second Title', $diff['title']);
    }
}

class PermissionRepositoryTest extends RepositoryTestCase
{
    protected function getRepositoryClass(): string
    {
        return PermissionRepository::class;
    }

    public function test_it_can_assign_permission_to_role()
    {
        $permission = Permission::factory()->create();
        $role = Role::factory()->create();

        $this->repository->assignToRole($permission->id, $role->id);

        $this->assertDatabaseHas('permission_role', [
            'permission_id' => $permission->id,
            'role_id' => $role->id
        ]);
    }

    public function test_it_can_get_permissions_by_group()
    {
        Permission::factory()->count(2)->create(['group' => 'content']);
        Permission::factory()->count(3)->create(['group' => 'users']);

        $grouped = $this->repository->getByGroup();

        $this->assertCount(2, $grouped['content']);
        $this->assertCount(3, $grouped['users']);
    }
}
