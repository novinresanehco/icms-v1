<?php

namespace App\Core\Alerts;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Core\Contracts\AlertSystemInterface;

class AlertSystem implements AlertSystemInterface
{
    private NotificationService $notifier;
    private EscalationManager $escalation;
    private array $config;

    private const ALERT_TTL = 3600;
    private const MAX_ALERTS_PER_MINUTE = 60;

    public function __construct(
        NotificationService $notifier,
        EscalationManager $escalation,
        array $config
    ) {
        $this->notifier = $notifier;
        $this->escalation = $escalation;
        $this->config = $config;
    }

    public function notifyCriticalViolation(array $violation): void
    {
        $alertId = $this->generateAlertId();
        
        Redis::multi();
        try {
            $this->storeAlert($alertId, 'critical', $violation);
            $this->notifyCritical($violation);
            $this->escalateCritical($violation);
            Redis::exec();
        } catch (\Exception $e) {
            Redis::discard();
            $this->handleAlertFailure($e, $violation);
        }
    }

    public function notifyWarningViolation(array $violation): void
    {
        if ($this->shouldThrottleAlerts()) {
            return;
        }

        $alertId = $this->generateAlertId();
        
        Redis::multi();
        try {
            $this->storeAlert($alertId, 'warning', $violation);
            $this->notifyWarning($violation);
            Redis::exec();
        } catch (\Exception $e) {
            Redis::discard();
            $this->handleAlertFailure($e, $violation);
        }
    }

    public function getAlertHistory(int $startTime, int $endTime): array
    {
        $alertIds = Redis::zRangeByScore('alerts:timeline', $startTime, $endTime);
        
        $alerts = [];
        foreach ($alertIds as $alertId) {
            $alert = Redis::hGetAll("alert:{$alertId}");
            if ($alert) {
                $alerts[] = $alert;
            }
        }
        
        return $alerts;
    }

    private function storeAlert(string $alertId, string $severity, array $violation): void
    {
        $alert = [
            'id' => $alertId,
            'severity' => $severity,
            'violation' => json_encode($violation),
            'timestamp' => microtime(true),
            'status' => 'new'
        ];

        Redis::hMSet("alert:{$alertId}", $alert);
        Redis::expire("alert:{$alertId}", self::ALERT_TTL);
        
        Redis::zAdd('alerts:timeline', microtime(true), $alertId);
    }

    private function notifyCritical(array $violation): void
    {
        foreach ($this->config['critical_channels'] as $channel) {
            $this->notifier->sendCritical($channel, $violation);
        }
    }

    private function notifyWarning(array $violation): void
    {
        foreach ($this->config['warning_channels'] as $channel) {
            $this->notifier->sendWarning($channel, $violation);
        }
    }

    private function escalateCritical(array $violation): void
    {
        $this->escalation->escalateViolation($violation);
    }

    private function shouldThrottleAlerts(): bool
    {
        $key = 'alerts:rate:' . (int)(time() / 60);
        $count = Redis::incr($key);
        Redis::expire($key, 60);
        
        return $count > self::MAX_ALERTS_PER_MINUTE;
    }

    private function generateAlertId(): string
    {
        return uniqid('alert_', true);
    }

    private function handleAlertFailure(\Exception $e, array $violation): void
    {
        Log::emergency('Failed to process alert', [
            'violation' => $violation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Ensure critical violations are not lost
        if ($violation['severity'] === 'critical') {
            $this->notifier->sendEmergency([
                'error' => 'Alert system failure',
                'violation' => $violation
            ]);
        }
    }
}
