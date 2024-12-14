namespace App\Core\Workflow;

class WorkflowEngine implements WorkflowInterface
{
    private SecurityManager $security;
    private WorkflowRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private EventDispatcher $events;

    public function __construct(
        SecurityManager $security,
        WorkflowRepository $repository,
        ValidationService $validator,
        CacheManager $cache,
        EventDispatcher $events
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->events = $events;
    }

    public function executeTransition(string $workflowId, string $transitionId, array $data = []): WorkflowResult
    {
        return $this->security->executeCriticalOperation(new class($workflowId, $transitionId, $data, $this->repository, $this->validator) implements CriticalOperation {
            private string $workflowId;
            private string $transitionId;
            private array $data;
            private WorkflowRepository $repository;
            private ValidationService $validator;

            public function __construct(
                string $workflowId, 
                string $transitionId,
                array $data,
                WorkflowRepository $repository,
                ValidationService $validator
            ) {
                $this->workflowId = $workflowId;
                $this->transitionId = $transitionId;
                $this->data = $data;
                $this->repository = $repository;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $workflow = $this->repository->findWorkflow($this->workflowId);
                
                if (!$workflow->canTransition($this->transitionId)) {
                    throw new WorkflowException('Invalid transition');
                }

                DB::beginTransaction();
                try {
                    $this->validator->validateTransitionData($workflow, $this->transitionId, $this->data);
                    
                    $result = $workflow->executeTransition(
                        $this->transitionId,
                        $this->data
                    );
                    
                    DB::commit();
                    return new OperationResult($result);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }

            public function getValidationRules(): array
            {
                return [
                    'workflow_id' => 'required|string',
                    'transition_id' => 'required|string'
                ];
            }

            public function getData(): array
            {
                return [
                    'workflow_id' => $this->workflowId,
                    'transition_id' => $this->transitionId
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['workflow.execute'];
            }

            public function getRateLimitKey(): string
            {
                return "workflow:execute:{$this->workflowId}";
            }
        });
    }

    public function createWorkflow(string $type, array $data = []): Workflow
    {
        return $this->security->executeCriticalOperation(new class($type, $data, $this->repository, $this->validator) implements CriticalOperation {
            private string $type;
            private array $data;
            private WorkflowRepository $repository;
            private ValidationService $validator;

            public function __construct(
                string $type,
                array $data,
                WorkflowRepository $repository,
                ValidationService $validator
            ) {
                $this->type = $type;
                $this->data = $data;
                $this->repository = $repository;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $this->validator->validateWorkflowData($this->type, $this->data);
                
                DB::beginTransaction();
                try {
                    $workflow = $this->repository->createWorkflow(
                        $this->type,
                        $this->data
                    );
                    
                    DB::commit();
                    return new OperationResult($workflow);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }

            public function getValidationRules(): array
            {
                return [
                    'type' => 'required|string',
                    'data' => 'array'
                ];
            }

            public function getData(): array
            {
                return [
                    'type' => $this->type,
                    'data' => $this->data
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['workflow.create'];
            }

            public function getRateLimitKey(): string
            {
                return "workflow:create:{$this->type}";
            }
        });
    }

    public function getAvailableTransitions(string $workflowId): array
    {
        return $this->security->executeCriticalOperation(new class($workflowId, $this->repository, $this->cache) implements CriticalOperation {
            private string $workflowId;
            private WorkflowRepository $repository;
            private CacheManager $cache;

            public function __construct(
                string $workflowId,
                WorkflowRepository $repository,
                CacheManager $cache
            ) {
                $this->workflowId = $workflowId;
                $this->repository = $repository;
                $this->cache = $cache;
            }

            public function execute(): OperationResult
            {
                $cacheKey = "workflow:transitions:{$this->workflowId}";
                
                return new OperationResult($this->cache->remember($cacheKey, function() {
                    $workflow = $this->repository->findWorkflow($this->workflowId);
                    return $workflow->getAvailableTransitions();
                }));
            }

            public function getValidationRules(): array
            {
                return ['workflow_id' => 'required|string'];
            }

            public function getData(): array
            {
                return ['workflow_id' => $this->workflowId];
            }

            public function getRequiredPermissions(): array
            {
                return ['workflow.read'];
            }

            public function getRateLimitKey(): string
            {
                return "workflow:transitions:{$this->workflowId}";
            }
        });
    }

    public function getWorkflowHistory(string $workflowId): array
    {
        return $this->security->executeCriticalOperation(new class($workflowId, $this->repository) implements CriticalOperation {
            private string $workflowId;
            private WorkflowRepository $repository;

            public function __construct(string $workflowId, WorkflowRepository $repository)
            {
                $this->workflowId = $workflowId;
                $this->repository = $repository;
            }

            public function execute(): OperationResult
            {
                $workflow = $this->repository->findWorkflow($this->workflowId);
                return new OperationResult($workflow->getHistory());
            }

            public function getValidationRules(): array
            {
                return ['workflow_id' => 'required|string'];
            }

            public function getData(): array
            {
                return ['workflow_id' => $this->workflowId];
            }

            public function getRequiredPermissions(): array
            {
                return ['workflow.history'];
            }

            public function getRateLimitKey(): string
            {
                return "workflow:history:{$this->workflowId}";
            }
        });
    }
}
