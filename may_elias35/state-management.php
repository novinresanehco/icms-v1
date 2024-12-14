<?php

namespace App\Core\State;

use App\Core\Interfaces\StateManagerInterface;
use App\Core\Exceptions\{StateException, SecurityException};
use Illuminate\Support\Facades\{DB, Cache};

class StateManager implements StateManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private LockManager $locks;
    private StateStore $store;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        LockManager $locks,
        StateStore $store
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->locks = $locks;
        $this->store = $store;
    }

    public function transitionState(string $newState): void
    {
        $transitionId = $this->generateTransitionId();
        
        try {
            DB::beginTransaction();

            // Lock state transition
            $this->locks->acquireStateLock($transitionId);
            
            // Validate transition
            $this->validateStateTransition($newState);
            
            // Backup current state
            $this->backupCurrentState();
            
            // Execute transition
            $this->executeStateTransition($newState);
            
            // Verify new state
            $this->verifyNewState($newState);
            
            DB::commit();
            
            $this->locks->releaseStateLock($transitionId);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTransitionFailure($e, $transitionId);
            throw $e;
        }
    }

    protected function validateStateTransition(string $newState): void
    {
        $currentState = $this->store->getCurrentState();
        
        if (!$this->validator->isValidTransition($currentState, $newState)) {
            throw new StateException("Invalid state transition: $currentState -> $newState");
        }

        if (!$this->security->validateStateTransition($currentState, $newState)) {
            throw new SecurityException("Unauthorized state transition");
        }
    }

    protected function backupCurrentState(): void
    {
        $currentState = $this->store->getCurrentState();
        $this->store->backupState($currentState);
    }

    protected function executeStateTransition(string $newState): void
    {
        // Execute pre-transition hooks
        $this->executePreTransitionHooks($newState);
        
        // Update state
        $this->store->setState($newState);
        
        // Execute post-transition hooks
        $this->executePostTransitionHooks($newState);
    }

    protected function verifyNewState(string $newState): void
    {
        $actualState = $this->store->getCurrentState();
        
        if ($actualState !== $newState) {
            throw new StateException("State transition verification failed");
        }

        if (!$this->validator->validateStateIntegrity($newState)) {
            throw new StateException("State integrity validation failed");
        }
    }

    protected function executePreTransitionHooks(string $newState): void
    {
        foreach ($this->getPreTransitionHooks($newState) as $hook) {
            $hook->execute();
        }
    }

    protected function executePostTransitionHooks(string $newState): void
    {
        foreach ($this->getPostTransitionHooks($newState) as $hook) {
            $hook->execute();
        }
    }

    protected function handleTransitionFailure(\Exception $e, string $transitionId): void
    {
        $this->locks->releaseStateLock($transitionId);
        $this->restoreLastKnownGoodState();
    }

    protected function restoreLastKnownGoodState(): void
    {
        $lastGoodState = $this->store->getLastKnownGoodState();
        $this->store->setState($lastGoodState);
    }

    protected function generateTransitionId(): string
    {
        return uniqid('transition:', true);
    }
}
