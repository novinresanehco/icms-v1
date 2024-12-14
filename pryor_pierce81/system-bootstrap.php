<?php

namespace App\Core\Bootstrap;

class CriticalSystemBootstrap implements BootstrapInterface
{
    private ValidatorRegistry $validatorRegistry;
    private InitializationEngine $initEngine;
    private SecurityManager $securityManager;
    private BootstrapLogger $logger;
    private EmergencyProtocol $emergency;
    private IntegrityVerifier $integrityVerifier;

    public function __construct(
        ValidatorRegistry $validatorRegistry,
        InitializationEngine $initEngine,
        SecurityManager $securityManager,
        BootstrapLogger $logger,
        EmergencyProtocol $emergency,
        IntegrityVerifier $integrityVerifier
    ) {
        $this->validatorRegistry = $validatorRegistry;
        $this->initEngine = $initEngine;
        $this->securityManager = $securityManager;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->integrityVerifier = $integrityVerifier;
    }

    public function initializeSystem(): InitializationResult
    {
        $initId = $this->generateInitializationId();
        
        try {
            DB::beginTransaction();

            // Phase 1: Core Validation
            $this->validateCoreComponents();
            $this->verifySystemIntegrity();
            $this->initializeSecurity();

            // Phase 2: System Initialization
            $initializationPlan = $this->createInitializationPlan();
            $this->validateInitializationPlan($initializationPlan);
            $this->executeInitialization($initializationPlan);

            // Phase 3: Verification
            $this->verifyInitialization();

            $result = new InitializationResult([
                'initializationId' => $initId,
                'status' => InitializationStatus::COMPLETED,
                'verificationResults' => $this->collectVerificationResults(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (InitializationException $e) {
            DB::rollBack();
            $this->handleInitializationFailure($e, $initId);
            throw new CriticalBootstrapException($e->getMessage(), $e);
        }
    }

    private function validateCoreComponents(): void
    {
        foreach ($this->validatorRegistry->getCoreValidators() as $validator) {
            if (!$validator->validate()) {
                throw new CoreValidationException(
                    "Core component validation failed: {$validator->getName()}"
                );
            }
        }
    }

    private function verifySystemIntegrity(): void
    {
        if (!$this->integrityVerifier->verifySystemIntegrity()) {
            $this->emergency->handleIntegrityFailure();
            throw new IntegrityException('System integrity verification failed');
        }
    }

    private function executeInitialization(InitializationPlan $plan): void
    {
        foreach ($plan->getPhases() as $phase) {
            try {
                $this->initEngine->executePhase($phase);
            } catch (\Exception $e) {
                $this->emergency->handlePhaseFailure($phase, $e);
                throw new InitializationPhaseException(
                    "Initialization phase failed: {$phase->getName()}",
                    previous: $e
                );
            }
        }
    }

    private function handleInitializationFailure(
        InitializationException $e, 
        string $initId
    ): void {
        $this->logger->logCriticalFailure($e, $initId);
        $this->emergency->handleBootstrapFailure($e);
        
        // Attempt system stabilization
        try {
            $this->emergency->stabilizeSystem();
        } catch (\Exception $stabilizationException) {
            $this->logger->logStabilizationFailure($stabilizationException);
            throw new SystemFailureException(
                'Complete system failure during bootstrap',
                previous: $stabilizationException
            );
        }
    }
}
