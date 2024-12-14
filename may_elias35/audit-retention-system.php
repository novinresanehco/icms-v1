// File: app/Core/Audit/Retention/RetentionManager.php
<?php

namespace App\Core\Audit\Retention;

class RetentionManager
{
    protected RetentionPolicy $policy;
    protected ArchiveManager $archiveManager;
    protected PurgeManager $purgeManager;

    public function apply(): void
    {
        $entries = $this->getExpiredEntries();
        
        foreach ($entries as $entry) {
            if ($this->policy->shouldArchive($entry)) {
                $this->archiveManager->archive($entry);
            } else {
                $this->purgeManager->purge($entry);
            }
        }
    }

    protected function getExpiredEntries(): array
    {
        return $this->repository->findExpired(
            $this->policy->getRetentionPeriod()
        );
    }
}
