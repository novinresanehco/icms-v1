<?php

namespace App\Core\Template\State;

use Illuminate\Support\Collection;
use App\Core\Template\Exceptions\StateException;

class StateManager
{
    private Collection $states;
    private Collection $transitions;
    private StateStorage $storage;
    private StateValidator $validator;
    private array $config;

    public function __construct(
        StateStorage $storage,
        StateValidator $validator,
        array $config = []
    ) {
        $this->states = new Collection();
        $this->transitions = new Collection();
        $this->storage = $storage;
        $this->validator = $validator;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Register template state
     *
     * @param string $name
     * @param array $config
     * @return State
     */
    public function registerState(string $name, array $config = []): State
    {
        $state = new State($name, $config);
        $this->states->put($name, $state);
        return $state;
    }

    /**
     * Register state transition
     *
     * @param string $from
     * @param string $to
     * @param array $conditions
     * @return Transition
     */
    public function registerTransition(string $from, string $to, array $conditions = []): Transition
    {
        $transition = new Transition($from, $to, $conditions);
        $key = $this->getTransitionKey($from, $to);
        $this->transitions->put($key, $transition);
        return $transition;
    }

    /**
     * Change template state
     *
     * @param string $templateId
     * @param string $newState
     * @param array $context
     * @return bool
     */
    public function changeState(string $templateId, string $newState, array $context = []): bool
    {
        $currentState = $this->storage->getCurrentState($templateId);
        
        if (!$this->canTransition($currentState, $newState, $context)) {
            throw new StateException("Invalid state transition from {$currentState} to {$newState}");
        }

        $transition = $this->getTransition($currentState, $newState);
        
        if (!$this->validator->validateTransition($transition, $context)) {
            throw new StateException("Transition validation failed");
        }

        return $this->storage->setState($templateId, $newState, [
            'previous_state' => $currentState,
            'context' => $context,
            'timestamp' => now()
        ]);
    }

    /**
     * Check if state transition is possible
     *
     * @param string $currentState
     * @param string $newState
     * @param array $context
     * @return bool
     */
    public function canTransition(string $currentState, string $newState, array $context = []): bool
    {
        if (!$this->states->has($newState)) {
            return false;
        }

        $key = $this->getTransitionKey($currentState, $newState);
        if (!$this->transitions->has($key)) {
            return false;
        }

        $transition = $this->transitions->get($key);
        return $this->validator->validateConditions($transition->getConditions(), $context);
    }

    /**
     * Get state history
     *
     * @param string $templateId
     * @return Collection
     */
    public function getHistory(string $templateId): Collection
    {
        return $this->storage->getStateHistory($templateId);
    }

    /**
     * Get available transitions
     *
     * @param string $currentState
     * @return Collection
     */
    public function getAvailableTransitions(string $currentState): Collection
    {
        return $this->transitions->filter(function ($transition) use ($currentState) {
            return $transition->getFromState() === $currentState;
        });
    }

    /**
     * Get transition key
     *
     * @param string $from
     * @param string $to
     * @return string
     */
    protected function getTransitionKey(string $from, string $to): string
    {
        return "{$from}->{$to}";
    }

    /**
     * Get transition
     *
     * @param string $from
     * @param string $to
     * @return Transition
     */
    protected function getTransition(string $from, string $to): Transition
    {
        $key = $this->getTransitionKey($from, $to);
        
        if (!$this->transitions->has($key)) {
            throw new StateException("Transition not found: {$key}");
        }

        return $this->transitions->get($key);
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'track_history' => true,
            'validate_transitions' => true,
            'auto_cleanup' => true,
            'history_limit' => 100
        ];
    }
}

class State
{
    private string $name;
    private array $config;

    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;
        $this->config = array_merge([
            'description' => '',
            'metadata' => [],
            'allowed_roles' => [],
            'final' => false
        ], $config);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isFinal(): bool
    {
        return $this->config['final'];
    }

    public function isAllowed(string $role): bool
    {
        return empty($this->config['allowed_roles']) || 
               in_array($role, $this->config['allowed_roles']);
    }
}

class Transition
{
    private string $fromState;
    private string $toState;
    private array $conditions;

    public function __construct(string $fromState, string $toState, array $conditions = [])
    {
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->conditions = $conditions;
    }

    public function getFromState(): string
    {
        return $this->fromState;
    }

    public function getToState(): string
    {
        return $this->toState;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }
}

class StateValidator
{
    /**
     * Validate state transition
     *
     * @param Transition $transition
     * @param array $context
     * @return bool
     */
    public function validateTransition(Transition $transition, array $context): bool
    {
        foreach ($transition->getConditions() as $condition) {
            if (!$this->validateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate conditions
     *
     * @param array $conditions
     * @param array $context
     * @return bool
     */
    public function validateConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            if (!$this->validateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate single condition
     *
     * @param array $condition
     * @param array $context
     * @return bool
     */
    protected function validateCondition(array $condition, array $context): bool
    {
        $type = $condition['type'] ?? 'default';
        
        return match($type) {
            'role' => $this->validateRoleCondition($condition, $context),
            'permission' => $this->validatePermissionCondition($condition, $context),
            'custom' => $this->validateCustomCondition($condition, $context),
            default => true
        };
    }

    /**
     * Validate role condition
     *
     * @param array $condition
     * @param array $context
     * @return bool
     */
    protected function validateRoleCondition(array $condition, array $context): bool
    {
        $requiredRole = $condition['role'];
        $userRoles = $context['user_roles'] ?? [];
        
        return in_array($requiredRole, $userRoles);
    }

    /**
     * Validate permission condition
     *
     * @param array $condition
     * @param array $context
     * @return bool
     */
    protected function validatePermissionCondition(array $condition, array $context): bool
    {
        $requiredPermission = $condition['permission'];
        $userPermissions = $context['user_permissions'] ?? [];
        
        return in_array($requiredPermission, $userPermissions);
    }

    /**
     * Validate custom condition
     *
     * @param array $condition
     * @param array $context
     * @return bool
     */
    protected function validateCustomCondition(array $condition, array $context): bool
    {
        if (isset($condition['callback']) && is_callable($condition['callback'])) {
            return call_user_func($condition['callback'], $context);
        }
        
        return false;
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\State\StateManager;
use App\Core\Template\State\StateStorage;
use App\Core\Template\State\StateValidator;

class StateServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(StateManager::class, function ($app) {
            return new StateManager(
                new StateStorage(),
                new StateValidator(),
                config('template.state')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $manager = $this->app->make(StateManager::class);

        // Register default states
        $manager->registerState('draft', [
            'description' => 'Initial template state',
            'allowed_roles' => ['editor', 'admin']
        ]);

        $manager->registerState('review', [
            'description' => 'Template under review',
            'allowed_roles' => ['reviewer', 'admin']
        ]);

        $manager->registerState('published', [
            'description' => 'Template is live',
            'allowed_roles' => ['admin'],
            'final' => true
        ]);

        // Register default transitions
        $manager->registerTransition('draft', 'review', [
            ['type' => 'role', 'role' => 'editor']
        ]);

        $manager->registerTransition('review', 'published', [
            ['type' => 'role', 'role' => 'admin']
        ]);
    }
}
