<?php

namespace App\Core\Workflow;

use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\WorkflowException;

class WorkflowManager
{
    private SecurityManager $security;
    private WorkflowValidator $validator;
    private VersionManager $versions;
    private StateManager $states;
    private array $config;

    public function __construct(
        SecurityManager $security,
        WorkflowValidator $validator,
        VersionManager $versions,
        StateManager $states,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->versions = $versions;
        $this->states = $states;
        $this->config = $config;
    }

    public function createVersion(ContentData $content, SecurityContext $context): Version
    {
        return $this->security->executeCriticalOperation(function() use ($content) {
            // Validate content
            $this->validator->validateContent($content);
            
            // Create version
            $version = $this->versions->create($content);
            
            // Initialize state
            $this->states->initialize($version);
            
            Event::dispatch('workflow.version.created', $version);
            
            return $version;
        }, $context);
    }

    public function transition(
        string $versionId,
        string $action,
        array $data,
        SecurityContext $context
    ): State {
        return $this->security->executeCriticalOperation(
            function() use ($versionId, $action, $data) {
                // Get current version
                $version = $this->versions->find($versionId);
                if (!$version) {
                    throw new WorkflowException("Version not found: {$versionId}");
                }
                
                // Validate transition
                $this->validator->validateTransition($version, $action, $data);
                
                // Execute transition
                $state = $this->states->transition($version, $action, $data);
                
                Event::dispatch('workflow.state.changed', [
                    'version' => $version,
                    'state' => $state,
                    'action' => $action
                ]);
                
                return $state;
            },
            $context
        );
    }

    public function getHistory(string $contentId, SecurityContext $context): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->versions->getHistory($contentId),
            $context
        );
    }

    public function getCurrentState(string $versionId, SecurityContext $context): State
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->states->getCurrentState($versionId),
            $context
        );
    }
}

class VersionManager
{
    private DB $db;
    private array $config;

    public function create(ContentData $content): Version
    {
        return DB::transaction(function() use ($content) {
            $data = [
                'content_id' => $content->id,
                'version' => $this->getNextVersion($content->id),
                'content' => $content->content,
                'metadata' => json_encode($content->metadata),
                'created_at' => now()
            ];
            
            $id = DB::table('content_versions')->insertGetId($data);
            return $this->find($id);
        });
    }

    public function find(string $id): ?Version
    {
        $data = DB::table('content_versions')->find($id);
        return $data ? new Version($data) : null;
    }

    public function getHistory(string $contentId): array
    {
        return DB::table('content_versions')
            ->where('content_id', $contentId)
            ->orderBy('version', 'desc')
            ->get()
            ->map(fn($v) => new Version($v))
            ->toArray();
    }

    private function getNextVersion(string $contentId): int
    {
        $latest = DB::table('content_versions')
            ->where('content_id', $contentId)
            ->max('version');
            
        return ($latest ?? 0) + 1;
    }
}

class StateManager
{
    private DB $db;
    private array $config;
    private array $transitions;

    public function initialize(Version $version): State
    {
        return DB::transaction(function() use ($version) {
            $data = [
                'version_id' => $version->id,
                'state' => $this->config['initial_state'],
                'data' => json_encode([]),
                'created_at' => now()
            ];
            
            $id = DB::table('workflow_states')->insertGetId($data);
            return $this->find($id);
        });
    }

    public function transition(Version $version, string $action, array $data): State
    {
        return DB::transaction(function() use ($version, $action, $data) {
            $currentState = $this->getCurrentState($version->id);
            
            $transition = $this->transitions[$currentState->state][$action] ?? null;
            if (!$transition) {
                throw new WorkflowException(
                    "Invalid transition: {$currentState->state} -> {$action}"
                );
            }
            
            $newState = [
                'version_id' => $version->id,
                'state' => $transition['target'],
                'data' => json_encode($data),
                'created_at' => now()
            ];
            
            $id = DB::table('workflow_states')->insertGetId($newState);
            return $this->find($id);
        });
    }

    public function getCurrentState(string $versionId): State
    {
        $state = DB::table('workflow_states')
            ->where('version_id', $versionId)
            ->orderBy('created_at', 'desc')
            ->first();
            
        return $state ? new State($state) : null;
    }

    public function find(string $id): ?State
    {
        $data = DB::table('workflow_states')->find($id);
        return $data ? new State($data) : null;
    }
}

class WorkflowValidator
{
    private array $config;
    private array $transitions;

    public function validateContent(ContentData $content): void
    {
        $validator = validator(
            (array)$content,
            [
                'id' => 'required|string',
                'content' => 'required|string',
                'metadata' => 'array'
            ]
        );
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }

    public function validateTransition(Version $version, string $action, array $data): void
    {
        if (!isset($this->transitions[$version->state][$action])) {
            throw new WorkflowException(
                "Invalid transition: {$version->state} -> {$action}"
            );
        }
        
        $transition = $this->transitions[$version->state][$action];
        
        $validator = validator($data, $transition['validation'] ?? []);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }
}

class Version
{
    public string $id;
    public string $content_id;
    public int $version;
    public string $content;
    public array $metadata;
    public string $created_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if ($key === 'metadata') {
                $this->metadata = json_decode($value, true);
            } elseif (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

class State
{
    public string $id;
    public string $version_id;
    public string $state;
    public array $data;
    public string $created_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if ($key === 'data') {
                $this->data = json_decode($value, true);
            } elseif (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

class ContentData
{
    public string $id;
    public string $content;
    public array $metadata;
}
