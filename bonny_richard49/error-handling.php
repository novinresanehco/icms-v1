<?php
namespace App\Core\Error;

class ErrorHandler
{
    private LogManager $logger;
    private AlertManager $alerts;
    private RecoveryManager $recovery;

    public function handleException(\Throwable $e): void
    {
        $this->logException($e);
        
        if ($this->isCriticalError($e)) {
            $this->handleCriticalError($e);
        }

        if ($this->isRecoverable($e)) {
            $this->attemptRecovery($e);
        }
    }

    private function handleCriticalError(\Throwable $e): void
    {
        $this->alerts->triggerCriticalAlert([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'time' => date('Y-m-d H:i:s')
        ]);

        $this->recovery->initiateEmergencyProtocol();
    }

    private function attemptRecovery(\Throwable $e): void
    {
        try {
            $this->recovery->recover($e);
        } catch (\Exception $recoveryError) {
            $this->logger->logRecoveryFailure($recoveryError);
            throw $recoveryError;
        }
    }
}

class RecoveryManager
{
    private BackupManager $backup;
    private SystemMonitor $monitor;
    private LogManager $logger;

    public function recover(\Throwable $e): void
    {
        $snapshot = $this->backup->getLastValidSnapshot();
        
        if (!$snapshot) {
            throw new RecoveryException('No valid backup snapshot found');
        }

        try {
            $this->backup->restore($snapshot);
            $this->verifySystemState();
        } catch (\Exception $recoveryError) {
            $this->logger->logRecoveryFailure($recoveryError);
            throw $recoveryError;
        }
    }

    public function verifySystemState(): void
    {
        if (!$this->monitor->checkSystemHealth()) {
            throw new SystemStateException('System state verification failed');
        }
    }

    public function initiateEmergencyProtocol(): void
    {
        try {
            DB::beginTransaction();
            
            $this->backup->createEmergencySnapshot();
            $this->monitor->enableEmergencyMode();
            $this->logger->logEmergencyProtocol();
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new EmergencyProtocolException('Emergency protocol failed', 0, $e);
        }
    }
}