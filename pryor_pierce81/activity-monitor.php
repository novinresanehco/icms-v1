<?php

namespace App\Core\Security;

class ActivityMonitor
{
    private $logger;
    private $alerter;
    private $store;

    public function trackActivity(string $userId, string $action): void
    {
        try {
            // Record activity
            $activity = [
                'user_id' => $userId,
                'action' => $action,
                'timestamp' => time(),
                'ip' => $_SERVER['REMOTE_ADDR']
            ];

            // Store encrypted
            $this->store->logActivity($activity);

            // Check for suspicious activity
            if ($this->isSuspicious($userId, $action)) {
                $this->handleSuspiciousActivity($activity);
            }

        } catch (\Exception $e) {
            $this->logger->critical('Activity tracking failed', [
                'error' => $e->getMessage(),
                'user' => $userId,
                'action' => $action
            ]);
            throw $e;
        }
    }

    private function isSuspicious(string $userId, string $action): bool
    {
        // Check frequency
        $recent = $this->store->getRecentActivity($userId, 300); // 5 minutes
        if (count($recent) > 100) { // Too many actions
            return true;
        }

        // Check patterns
        return $this->detectSuspiciousPattern($recent, $action);
    }

    private function handleSuspiciousActivity(array $activity): void
    {
        $this->alerter->sendSecurityAlert('Suspicious activity detected', $activity);
        $this->logger->warning('Suspicious activity', $activity);
    }
}
