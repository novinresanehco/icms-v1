<?php

namespace Tests\Unit\Repositories;

use App\Core\Repositories\WorkflowRepository;
use App\Exceptions\WorkflowException;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new WorkflowRepository();
    }

    public function test_can_create_workflow()
    {
        $config = [
            'name' => 'Test Workflow',
            'steps' => [
                [
                    'name' => 'First Step',
                    'type' => 'approval',
                    'config' => ['approvers' => 1]
                ]
            ]
        ];

        $workflow = $this->repository->createWorkflow('content_approval', $config);

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertEquals('Test Workflow', $workflow->name);
        $this->assertEquals('content_approval', $workflow->type);
        $this->assertCount(1, $workflow->steps);
    }

    public function test_throws_exception_for_invalid_config()
    {
        $this->expectException(WorkflowException::class);

        $config = [
            'steps' => [] // Missing required 'name' field
        ];

        $this->repository->createWorkflow('content_approval', $config);
    }

    public function test_can_add_workflow_step()
    {
        $workflow = Workflow::factory()->create();

        $stepData = [
            'name' => 'New Step',
            'type' => 'review',
            'config' => ['reviewers' => 2]
        ];

        $step = $this->repository->addWorkflowStep($workflow, $stepData);

        $this->assertInstanceOf(WorkflowStep::class, $step);
        $this->assertEquals('New Step', $step->name);
        $this->assertEquals(1, $step->position);
    }

    public function test_can_get_workflow_by_id()
    {
        $workflow = Workflow::factory()->create();

        $found = $this->repository->getWorkflowById($workflow->id);

        $this->assertInstanceOf(Workflow::class, $found);
        $this->assertEquals($workflow->id, $found->id);
    }

    public function test_can_get_workflows_by_type()
    {
        Workflow::factory()->count(3)->create(['type' => 'content_approval']);
        Workflow::factory()->create(['type' => 'document_review']);

        $workflows = $this->repository->getWorkflowsByType('content_approval');

        $this->assertCount(3, $workflows);
        $this->assertEquals('content_approval', $workflows->first()->type);
    }

    // Additional tests...
}
