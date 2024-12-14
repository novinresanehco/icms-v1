<?php

namespace Tests\Unit\Repositories;

use App\Core\Repositories\BaseRepository;
use App\Exceptions\RepositoryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaseRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test model instance
        $this->model = new class extends Model {
            protected $table = 'test_models';
            protected $fillable = ['name', 'description'];
        };

        // Create the test table
        $this->createTestTable();

        // Create repository instance
        $this->repository = new class($this->model) extends BaseRepository {
            // Concrete implementation for testing
        };
    }

    protected function createTestTable(): void
    {
        \Schema::create('test_models', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function test_can_create_record()
    {
        $data = [
            'name' => 'Test Model',
            'description' => 'Test Description'
        ];

        $model = $this->repository->create($data);

        $this->assertDatabaseHas('test_models', $data);
        $this->assertEquals('Test Model', $model->name);
    }

    public function test_can_find_by_id()
    {
        $model = $this->repository->create([
            'name' => 'Test Model',
            'description' => 'Test Description'
        ]);

        $found = $this->repository->findById($model->id);

        $this->assertNotNull($found);
        $this->assertEquals($model->id, $found->id);
    }

    public function test_can_update_record()
    {
        $model = $this->repository->create([
            'name' => 'Test Model',
            'description' => 'Test Description'
        ]);

        $updated = $this->repository->update($model->id, [
            'name' => 'Updated Name'
        ]);

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertDatabaseHas('test_models', [
            'id' => $model->id,
            'name' => 'Updated Name'
        ]);
    }

    public function test_can_delete_record()
    {
        $model = $this->repository->create([
            'name' => 'Test Model',
            'description' => 'Test Description'
        ]);

        $result = $this->repository->deleteById($model->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('test_models', [
            'id' => $model->id
        ]);
    }

    public function test_transaction_management()
    {
        $this->repository->beginTransaction();

        try {
            $model = $this->repository->create([
                'name' => 'Transaction Test',
                'description' => 'Test Description'
            ]);

            $this->repository->commit();

            $this->assertDatabaseHas('test_models', [
                'name' => 'Transaction Test'
            ]);
        } catch (\Exception $e) {
            $this->repository->rollBack();
            throw $e;
        }
    }
}
