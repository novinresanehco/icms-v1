<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Models\WorkflowHistory;
use App\Events\WorkflowStepCompleted;
use App\Repositories\WorkflowRepository;
use Illuminate\Support\Facades\DB;

class WorkflowService
{
    protected $workflowRepo;
    
    public function __construct(WorkflowRepository $workflowRepo)
    {
        $this->workflowRepo = $workflowRepo;
    }
    
    public function initializeWorkflow(Content $content, string $workflowType)
    {
        return DB::transaction(function () use ($content, $workflowType) {
            $workflow = $this->workflowRepo->create([
                'content_id' => $content->id,
                'type' => $workflowType,
                'status' => 'active',
                'current_step' => 1
            ]);
            
            $this->createWorkflowSteps($workflow);
            return $workflow;
        });
    }
    
    protected function createWorkflowSteps(Workflow $workflow)
    {
        $steps = config("workflows.{$workflow->type}.steps");
        
        foreach ($steps as $position => $step) {
            WorkflowStep::create([
                'workflow_id' => $workflow->id,
                'position' => $position + 1,
                'name' => $step['name'],
                'type' => $step['type'],
                'assignee_role' => $step['assignee_role'],
                'status' => $position === 0 ? 'active' : 'pending'
            ]);
        }
    }
    
    public function completeStep(Workflow $workflow, array $data)
    {
        return DB::transaction(function () use ($workflow, $data) {
            $currentStep = $workflow->getCurrentStep();
            
            WorkflowHistory::create([
                'workflow_id' => $workflow->id,
                'step_id' => $currentStep->id,
                'user_id' => auth()->id(),
                'action' => 'complete',
                'comments' => $data['comments'] ?? null,
                'metadata' => $data
            ]);
            
            $currentStep->update(['status' => 'completed']);
            
            $nextStep = $workflow->getNextStep();
            if ($nextStep) {
                $nextStep->update(['status' => 'active']);
                $workflow->update(['current_step' => $nextStep->position]);
            } else {
                $workflow->update(['status' => 'completed']);
                $this->handleWorkflowCompletion($workflow);
            }
            
            event(new WorkflowStepCompleted($workflow, $currentStep));
            
            return $workflow->fresh();
        });
    }
    
    public function rejectStep(Workflow $workflow, array $data)
    {
        return DB::transaction(function () use ($workflow, $data) {
            $currentStep = $workflow->getCurrentStep();
            
            WorkflowHistory::create([
                'workflow_id' => $workflow->id,
                'step_id' => $currentStep->id,
                'user_id' => auth()->id(),
                'action' => 'reject',
                'comments' => $data['comments'],
                'metadata' => $data
            ]);
            
            $previousStep = $workflow->getPreviousStep();
            if ($previousStep) {
                $currentStep->update(['status' => 'pending']);
                $previousStep->update(['status' => 'active']);
                $workflow->update(['current_step' => $previousStep->position]);
            } else {
                $workflow->update(['status' => 'rejected']);
            }
            
            return $workflow->fresh();
        });
    }
    
    protected function handleWorkflowCompletion(Workflow $workflow)
    {
        $content = $workflow->content;
        
        switch ($workflow->type) {
            case 'publication':
                $content->update([
                    'status' => 'published',
                    'published_at' => now()
                ]);
                break;
                
            case 'review':
                $content->update(['status' => 'approved']);
                break;
        }
    }
}

namespace App\Services;

use App\Models\Content;
use App\Models\Version;
use App\Events\VersionCreated;

class VersioningService
{
    public function createVersion(Content $content)
    {
        $version = Version::create([
            'content_id' => $content->id,
            'user_id' => auth()->id(),
            'data' => $this->serializeContent($content),
            'version' => $this->getNextVersion($content)
        ]);
        
        event(new VersionCreated($version));
        return $version;
    }
    
    public function restoreVersion(Version $version)
    {
        $content = $version->content;
        $data = $this->unserializeContent($version->data);
        
        $content->update($data);
        return $content->fresh();
    }
    
    protected function serializeContent(Content $content): array
    {
        return [
            'title' => $content->title,
            'content' => $content->content,
            'excerpt' => $content->excerpt,
            'metadata' => $content->metadata,
            'category_id' => $content->category_id,
            'tags' => $content->tags->pluck('id')->toArray()
        ];
    }
    
    protected function unserializeContent(array $data): array
    {
        return array_except($data, ['tags']);
    }
    
    protected function getNextVersion(Content $content): int
    {
        return $content->versions()->max('version') + 1;
    }
}

namespace App\Services;

use App\Models\Content;
use App\Models\Schedule;

class SchedulingService
{
    public function schedule(Content $content, array $data)
    {
        return Schedule::create([
            'content_id' => $content->id,
            'action' => $data['action'],
            'scheduled_at' => $data['scheduled_at'],
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? []
        ]);
    }
    
    public function processScheduledActions()
    {
        Schedule::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->chunk(100, function($schedules) {
                foreach ($schedules as $schedule) {
                    $this->executeScheduledAction($schedule);
                }
            });
    }
    
    protected function executeScheduledAction(Schedule $schedule)
    {
        $content = $schedule->content;
        
        switch ($schedule->action) {
            case 'publish':
                $content->update([
                    'status' => 'published',
                    'published_at' => now()
                ]);
                break;
                
            case 'unpublish':
                $content->update([
                    'status' => 'draft',
                    'published_at' => null
                ]);
                break;
                
            case 'archive':
                $content->update(['status' => 'archived']);
                break;
        }
        
        $schedule->update(['status' => 'completed']);
    }
}
