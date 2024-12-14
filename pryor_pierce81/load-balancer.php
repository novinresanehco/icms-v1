<?php

namespace App\Core\Infrastructure;

class CriticalLoadBalancer
{
    private ServerPool $servers;
    private HealthChecker $health;
    private Monitor $monitor;

    public function getServer(): Server
    {
        $serverId = $this->monitor->startBalance();

        try {
            // Get active servers
            $active = $this->health->getActiveServers();
            if (empty($active)) {
                throw new NoServersException('No active servers available');
            }

            // Select least loaded
            $server = $this->selectOptimalServer($active);
            
            $this->monitor->balanceSuccess($serverId);
            return $server;

        } catch (\Exception $e) {
            $this->monitor->balanceFailure($serverId, $e);
            throw $e;
        }
    }

    private function selectOptimalServer(array $servers): Server
    {
        return array_reduce($servers, function($optimal, $server) {
            if (!$optimal || $server->getLoad() < $optimal->getLoad()) {
                return $server;
            }
            return $optimal;
        });
    }
}
