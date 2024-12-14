<?php

namespace App\Core\Security\Integrity;

class SystemIntegrityManager implements IntegrityManagerInterface
{
    private StateValidator $stateValidator;
    private IntegrityChecker $integrityChecker;
    private HashValidator $hashValidator;
    private CryptoService $cryptoService;
    private IntegrityLogger $logger;
    private AlertDispatcher $alerts;

    public function __construct(
        StateValidator $stateValidator,
        IntegrityChecker $integrityChecker,
        HashValidator $hashValidator,
        CryptoService $cryptoService,
        IntegrityLogger $logger,
        AlertDispatcher $alerts
    ) {
        $this->stateValidator = $stateValidator;
        $this->integrityChecker = $integrityChecker;
        $this->hashValidator = $hashValidator;
        $this->cryptoService = $cryptoService;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function verifySystemIntegrity(): IntegrityResult
    {
        DB::beginTransaction();
        try {
            $state = $this->stateValidator->captureState();
            $hashes = $this->hashValidator->computeHashes($state);
            $integrityCheck = $this->integrityChecker->verify($state, $hashes);

            if (!$integrityCheck->isValid()) {
                throw new IntegrityViolationException(
                    'System integrity check failed',
                    $integrityCheck->getViolations()
                );
            }

            $this->cryptoService->signState($state);
            $this->logger->logIntegrityCheck($state, $integrityCheck);
            
            DB::commit();
            return new IntegrityResult(true, $integrityCheck);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleIntegrityFailure($e);
            throw new CriticalIntegrityException($e->getMessage(), $e);
        }
    }

    private function handleIntegrityFailure(\Exception $e): void
    {
        $this->logger->logFailure($e);
        $this->alerts->dispatch(
            new IntegrityAlert('System integrity verification failed', $e)
        );
    }
}

