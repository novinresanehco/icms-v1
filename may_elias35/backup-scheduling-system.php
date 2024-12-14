// File: app/Core/Backup/Scheduler/BackupScheduler.php
<?php

namespace App\Core\Backup\Scheduler;

class BackupScheduler
{
    protected ScheduleRepository $repository;
    protected BackupManager $backupManager;
    protected CronManager $cronManager;
    protected ScheduleConfig $config;

    public function schedule(BackupSchedule $schedule): void
    {
        $this->validateSchedule($schedule);
        
        DB::beginTransaction();
        try {
            // Save schedule
            $this->repository->save($schedule);
            
            // Register cron job
            $this->cronManager->register(
                $schedule->getCronExpression(),
                new CreateBackupJob($schedule->getBackupConfig())
            );
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ScheduleException("Failed to create schedule: " . $e->getMessage());
        }
    }

    public function executeSchedule(BackupSchedule $schedule): void
    {
        try {
            $backup = $this->backupManager->create($schedule->getBackupConfig());
            
            $schedule->setLastRun(now());
            $schedule->setLastBackupId($backup->getId());
            $this->repository->save($schedule);
            
        } catch (\Exception $e) {
            $schedule->setLastError($e->getMessage());
            $this->repository->save($schedule);
            
            throw $e;
        }
    }
}
