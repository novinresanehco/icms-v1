// File: app/Core/Report/Schedule/ReportScheduler.php
<?php

namespace App\Core\Report\Schedule;

class ReportScheduler
{
    protected ScheduleRepository $repository;
    protected ReportManager $reportManager;
    protected Distributor $distributor;
    protected ScheduleConfig $config;

    public function schedule(ReportSchedule $schedule): void
    {
        $this->validateSchedule($schedule);
        
        DB::beginTransaction();
        try {
            // Save schedule
            $this->repository->save($schedule);
            
            // Register cron job
            $this->cronManager->register(
                $schedule->getExpression(),
                new GenerateReportJob($schedule)
            );
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ScheduleException("Failed to create schedule: " . $e->getMessage());
        }
    }

    protected function validateSchedule(ReportSchedule $schedule): void
    {
        if (!$this->config->isValidExpression($schedule->getExpression())) {
            throw new InvalidScheduleException("Invalid schedule expression");
        }

        if (!$this->config->isValidRecipients($schedule->getRecipients())) {
            throw new InvalidScheduleException("Invalid recipients configuration");
        }
    }
}
