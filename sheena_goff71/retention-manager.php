<?php

namespace App\Core\Retention;

class RetentionManager
{
    private StorageVerifier $verifier;
    private RetentionPolicy $policy;
    private ArchiveService $archive;

    public function enforceRetention(): void
    {
        DB::transaction(function() {
            $this->verifyStorageIntegrity();
            $this->applyRetentionPolicies();
            $this->archiveData();
            $this->validateArchive();
        });
    }

    private function verifyStorageIntegrity(): void
    {
        if (!$this->verifier->verify()) {
            throw new StorageIntegrityException();
        }
    }

    private function applyRetentionPolicies(): void
    {
        foreach ($this->policy->getActivePolicies() as $policy) {
            $this->enforcePolicy($policy);
        }
    }

    private function enforcePolicy(RetentionPolicy $policy): void
    {
        if (!$this->policy->enforce($policy)) {
            throw new PolicyEnforcementException();
        }
    }
}
