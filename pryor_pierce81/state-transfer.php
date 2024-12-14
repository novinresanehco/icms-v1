<?php

namespace App\Core\State;

class CriticalStateTransfer implements StateTransferInterface
{
    private TransferValidator $validator;
    private StateSerializer $serializer;
    private IntegrityVerifier $integrityVerifier;
    private TransferLogger $logger;
    private EmergencyProtocol $emergency;
    private SecurityManager $security;

    public function __construct(
        TransferValidator $validator,
        StateSerializer $serializer,
        IntegrityVerifier $integrityVerifier,
        TransferLogger $logger,
        EmergencyProtocol $emergency,
        SecurityManager $security
    ) {
        $this->validator = $validator;
        $this->serializer = $serializer;
        $this->integrityVerifier = $integrityVerifier;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->security = $security;
    }

    public function transferState(TransferContext $context): TransferResult
    {
        $transferId = $this->initializeTransfer($context);
        
        try {
            DB::beginTransaction();

            $sourceState = $this->validateSourceState($context->getSourceState());
            $serializedState = $this->serializeState($sourceState);
            $this->verifySerializedState($serializedState);

            // Secure state transfer
            $encryptedState = $this->security->encryptState($serializedState);
            $transferResult = $this->executeTransfer($encryptedState, $context);
            $this->verifyTransferResult($transferResult);

            $result = new TransferResult([
                'transferId' => $transferId,
                'sourceState' => $sourceState,
                'transferResult' => $transferResult,
                'integrity' => $this->calculateIntegrityHash($transferResult),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (TransferException $e) {
            DB::rollBack();
            $this->handleTransferFailure($e, $transferId);
            throw new CriticalTransferException($e->getMessage(), $e);
        }
    }

    private function validateSourceState(SystemState $state): SystemState
    {
        if (!$this->validator->validateState($state)) {
            throw new InvalidStateException('Source state validation failed');
        }

        if (!$this->integrityVerifier->verifyStateIntegrity($state)) {
            $this->emergency->handleIntegrityFailure($state);
            throw new IntegrityException('Source state integrity verification failed');
        }

        return $state;
    }

    private function verifySerializedState(SerializedState $state): void
    {
        if (!$this->validator->validateSerialization($state)) {
            throw new SerializationException('State serialization validation failed');
        }
    }

    private function handleTransferFailure(TransferException $e, string $transferId): void
    {
        $this->logger->logFailure($e, $transferId);
        
        if ($e->isCritical()) {
            $this->emergency->handleTransferFailure($e, $transferId);
            $this->security->lockdownTransferChannel();
        }
    }
}
