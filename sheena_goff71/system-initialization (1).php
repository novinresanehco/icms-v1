<?php

namespace App\Core\Initialization;

class SystemInitializationControl implements InitializationInterface
{
    private SystemValidator $validator;
    private SecurityBootstrap $security;
    private StateManager $state;
    private ResourceController $resources;
    private EmergencyHandler $emergency;

    public function __construct(
        SystemValidator $validator,
        SecurityBootstrap $security,
        StateManager $state,
        ResourceController $resources,
        EmergencyHandler $emergency
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->state = $state;
        $this->resources = $resources;
        $this->emergency = $emergency;
    }

    public function initializeSystem(): InitializationResult
    {
        $initId = $this->generateInitializationId();
        DB::beginTransaction();

        try {
            // Pre-initialization validation
            $validation = $this->validator->validateSystemState();
            if (!$validation->isValid()) {
                throw new ValidationException($validation->getViolations());
            }

            // Initialize security protocols
            $securityState = $this->security->initializeSecurity();
            if (!$securityState->isSecure()) {
                throw new SecurityException('Security initialization failed');
            }

            // Initialize core components
            $initResult = $this->initializeCoreComponents($initId);

            // Verify initialization
            $this->verifyInitialization($initResult);

            $this->logInitialization($initId, $initResult);
            DB::commit();

            return $initResult;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleInitializationFailure($initId, $e);
            throw $e;
        }
    }

    private function initializeCoreComponents(string $initId): InitializationResult
    {
        // Initialize state management
        $stateInit = $this->state->initialize();
        
        // Initialize resource management
        $resourceInit = $this->resources->initialize();
        
        // Initialize critical subsystems
        $subsystemInit = $this->initializeSubsystems();

        return new InitializationResult(
            success: true,
            initId: $initId,
            state: $stateInit,
            resources: $resourceInit,
            subsystems: $subsystemInit
        );
    }

    private function verifyInitialization(InitializationResult $result): void
    {
        // Verify state integrity
        if (!$this->validator->verifyStateIntegrity($result)) {
            throw new IntegrityException('State integrity verification failed');
        }

        // Verify security state
        if (!$this->security->verifySecurityState($result)) {
            throw new SecurityException('Security state verification failed');
        }

        // Verify resource allocation
        if (!$this->resources->verifyResourceState($result)) {
            throw new ResourceException('Resource state verification failed');
        }
    }

    private function initializeSubsystems(): SubsystemInitResult
    {
        $subsystems = [
            'monitoring' => $this->initializeMonitoring(),
            'logging' => $this->initializeLogging(),
            'validation' => $this->initializeValidation(),
            'emergency' => $this->initializeEmergency()
        ];

        foreach ($subsystems as $name => $result) {
            if (!$result->isSuccessful()) {
                throw new SubsystemException("Subsystem initialization failed: $name");
            }
        }

        return new SubsystemInitResult($subsystems);
    }

    private function handleInitializationFailure(
        string $initId,
        \Exception $e
    ): void {
        Log::emergency('System initialization failed', [
            'init_id' => $initId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->emergency->handleInitializationFailure(
            $initId,
            $e
        );

        // Attempt emergency recovery
        $this->attemptEmergencyRecovery($initId);
    }

    private function attemptEmergencyRecovery(string $initId): void
    {
        try {
            $this->emergency->executeEmergencyRecovery($initId);
        } catch (\Exception $e) {
            Log::emergency('Emergency recovery failed', [
                'init_id' => $initId,
                'error' => $e->getMessage()
            ]);
            $this->emergency->escalateToHighestLevel($initId, $e);
        }
    }

    private function generateInitializationId(): string
    {
        return Str::uuid();
    }

    private function logInitialization(
        string $initId,
        InitializationResult $result
    ): void {
        Log::info('System initialization completed', [
            'init_id' => $initId,
            'state' => $result->getStateSnapshot(),
            'timestamp' => now()
        ]);
    }

    private function initializeMonitoring(): SubsystemResult
    {
        return $this->executeWithValidation(function() {
            // Initialize monitoring subsystem
            return new SubsystemResult(true);
        });
    }

    private function initializeLogging(): SubsystemResult
    {
        return $this->executeWithValidation(function() {
            // Initialize logging subsystem
            return new SubsystemResult(true);
        });
    }

    private function initializeValidation(): SubsystemResult
    {
        return $this->executeWithValidation(function() {
            // Initialize validation subsystem
            return new SubsystemResult(true);
        });
    }

    private function initializeEmergency(): SubsystemResult
    {
        return $this->executeWithValidation(function() {
            // Initialize emergency subsystem
            return new SubsystemResult(true);
        });
    }

    private function executeWithValidation(callable $initialization): SubsystemResult
    {
        try {
            return $initialization();
        } catch (\Exception $e) {
            Log::error('Subsystem initialization failed', [
                'error' => $e->getMessage()
            ]);
            return new SubsystemResult(false);
        }
    }
}