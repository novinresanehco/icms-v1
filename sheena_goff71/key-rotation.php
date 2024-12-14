<?php

namespace App\Core\Security;

class KeyRotationSystem 
{
    private KeyStore $store;
    private RotationScheduler $scheduler;
    private SecurityMonitor $monitor;

    public function rotateKeys(): void
    {
        DB::transaction(function() {
            $this->validateRotationState();
            $this->performRotation();
            $this->verifyRotation();
            $this->updateSystemState();
        });
    }

    private function validateRotationState(): void
    {
        if (!$this->monitor->validateKeyState()) {
            throw new SecurityStateException("Invalid key state");
        }
    }

    private function performRotation(): void
    {
        foreach ($this->store->getActiveKeys() as $key) {
            if ($this->scheduler->shouldRotate($key)) {
                $this->rotateKey($key);
            }
        }
    }

    private function rotateKey(EncryptionKey $key): void
    {
        $newKey = $this->store->generateNewKey();
        $this->updateReferences($key, $newKey);
        $this->monitor->logRotation($key, $newKey);
    }
}
