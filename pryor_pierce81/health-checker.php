<?php

namespace App\Core\Infrastructure;

class CriticalHealthChecker
{
    private Monitor $monitor;
    private Alerts $alerts;

    public function checkHealth(Server $server): bool
    {
        $checkId = $this->monitor->startHealthCheck($server);

        try {
            // Basic connectivity
            if (!$this->pingServer($server)) {
                throw new ServerDownException();
            }

            // Check resources
            $resources = $this->checkResources($server);
            if (!$this->validateResources($resources)) {
                throw new ResourceException();
            }

            // Verify services
            $services = $this->checkServices($server);
            if (!$this->validateServices($services)) {
                throw new ServiceException();
            }

            $this->monitor->healthCheckSuccess($checkId);
            return true;

        } catch (\Exception $e) {
            $this->handleHealthCheckFailure($server, $e);
            return false;
        }
    }

    private function handleHealthCheckFailure(Server $server, \Exception $e): void
    {
        $this->alerts->serverHealthIssue($server, $e);
        $this->monitor->logHealthFailure($server, $e);
    }
}
