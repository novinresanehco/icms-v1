<?php

namespace App\Core\Database;

class DatabaseFailover
{
    private $connections;
    private $monitor;
    private $alerter;

    public function handleFailure(\Exception $e): void
    {
        try {
            // Log failure
            $this->monitor->logDatabaseFailure($e);
            
            // Try backup connection
            if ($this->switchToBackup()) {
                $this->alerter->databaseFailover();
                return;
            }
            
            // Critical failure - both main and backup failed
            $this->handleCriticalFailure();
            
        } catch (\Exception $inner) {
            $this->handleCatastrophicFailure($e, $inner);
        }
    }

    private function switchToBackup(): bool
    {
        foreach ($this->connections->getBackups() as $connection) {
            if ($this->testConnection($connection)) {
                DB::setDefaultConnection($connection);
                return true;
            }
        }
        return false;
    }

    private function handleCriticalFailure(): void 
    {
        $this->alerter->criticalDatabaseFailure();
        throw new CriticalDatabaseException('All database connections failed');
    }
}
