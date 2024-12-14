<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Log;
use App\Core\Contracts\NotificationInterface;

class AlertService
{
    private NotificationManager $notifications;
    private SecurityManager $security;
    private MetricsStore $store;
    
    private const ALERT_CACHE_TTL = 300; // 5 minutes
    private const MAX_ALERTS_PER_PERIOD = 10;
    private const ALERT_PERIOD = 3600; // 1 hour

    public function __construct(
        NotificationManager $notifications,
        SecurityManager $security,
        MetricsStore $store
    ) {
        $this->notifications = $notifications;
        $this->security = $security;
        $this->store = $store;
    }

    public function sendCriticalAlert(array $violation): void
    {
        try {
            // Validate alert before sending
            $this->validateAlert($violation);
            
            // Check rate limiting
            if ($this->isAlertRateLimited($violation)) {
                $this->handleRateLimitedAlert($violation);
                return;
            }
            
            // Prepare alert data
            $alert = $this->prepareAlert($violation, 'critical');
            
            // Log alert
            $this->logAlert($alert);
            
            // Send notifications
            $this->sendNotifications($alert);
            
            // Store alert
            $this->storeAlert($alert);
            
        } catch (\Exception $e) {
            Log::error('Failed to send critical alert', [
                'violation' => $violation,
                'error' => $e->getMessage()
            ]);
            throw new AlertException('Failed to send critical alert: ' . $e->getMessage(), $e);
        }
    }

    public function sendWarningAlert(array $violation): void
    {
        if ($this->isAlertRateLimited($violation)) {
            return;
        }
        
        $alert = $this->prepareAlert($violation, 'warning');
        $this->processAlert($alert);
    }

    public function sendInfoAlert(array $violation): void
    {
        if ($this->isAlertRateLimited($violation)) {
            return;
        }
        
        $alert = $this->prepareAlert($violation, 'info');
        $this->processAlert($alert);
    }

    private function validateAlert(array $violation): void
    {
        if (!isset($violation['threshold'], $violation['value'], $violation['limit'])) {
            throw new AlertException('Invalid alert data');
        }
        
        if (!$this->security->validateAlert($violation)) {
            throw new SecurityException('Alert validation failed');
        }
    }

    private function isAlertRateLimited(array $violation): bool
    {
        $key = $this->getAlertKey($violation);
        $count = $this->store->getAlertCount($key, self::ALERT_PERIOD);
        
        return $count >= self::MAX_ALERTS_PER_PERIOD;
    }

    private function handleRateLimitedAlert(array $violation): void
    {
        $key = $this->getAlertKey($violation);
        
        // Log rate limiting
        Log::warning('Alert rate limited', [
            'key' => $key,
            'violation' => $violation,
            'period' => self::ALERT_PERIOD,
            'max_alerts' => self::MAX_ALERTS_PER_PERIOD
        ]);
        
        // Store summary for later reporting
        $this->store->incrementRateLimitedAlerts($key);
    }

    private function prepareAlert(array $violation, string $severity): array
    {
        return [
            'id' => uniqid('alert_', true),
            'timestamp' => microtime(true),
            'severity' => $severity,
            'violation' => $violation,
            'context' => [
                'security' => $this->security->getSecurityContext(),
                'system' => $this->getSystemContext()
            ]
        ];
    }

    private function processAlert(array $alert): void
    {
        try {
            DB::beginTransaction();
            
            // Log alert
            $this->logAlert($alert);
            
            // Send notifications
            $this->sendNotifications($alert);
            
            // Store alert
            $this->storeAlert($alert);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process alert', [
                'alert' => $alert,
                'error' => $e->getMessage()
            ]);
            throw new AlertException('Failed to process alert: ' . $e->getMessage(), $e);
        }
    }

    private function logAlert(array $alert): void
    {
        Log::channel('alerts')->log(
            $alert['severity'],
            'Threshold violation alert',
            $alert
        );
    }

    private function sendNotifications(array $alert): void
    {
        $recipients = $this->getAlertRecipients($alert);
        
        foreach ($recipients as $recipient) {
            $this->notifications->send(
                $recipient,
                $this->formatAlertMessage($alert),
                $alert
            );
        }
    }

    private function storeAlert(array $alert): void
    {
        $this->store->storeAlert($alert);
        $this->updateAlertStatistics($alert);
    }

    private function getAlertKey(array $violation): string
    {
        return sprintf(
            'alert_%s_%s',
            $violation['threshold'],
            $violation['severity']
        );
    }

    private function getAlertRecipients(array $alert): array
    {
        return $this->store->getAlertRecipients($alert['severity']);
    }

    private function formatAlertMessage(array $alert): string
    {
        $template = $this->store->getAlertTemplate($alert['severity']);
        return $this->replaceAlertPlaceholders($template, $alert);
    }

    private function updateAlertStatistics(array $alert): void
    {
        $this->store->updateAlertStats([
            'type' => $alert['violation']['threshold'],
            'severity' => $alert['severity'],
            'timestamp' => $alert['timestamp']
        ]);
    }
}
