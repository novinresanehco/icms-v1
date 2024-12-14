<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\AlertInterface;
use App\Core\Security\Events\SecurityEvent;
use App\Core\Security\Notifications\SecurityNotification;

class AlertService implements AlertInterface 
{
    private NotificationManager $notifications;
    private IncidentManager $incidents;
    private ThresholdManager $thresholds;
    private array $config;

    private const CRITICAL_ALERT_TIMEOUT = 5;
    private const MAX_RETRY_ATTEMPTS = 3;

    public function __construct(
        NotificationManager $notifications,
        IncidentManager $incidents,
        ThresholdManager $thresholds,
        array $config
    ) {
        $this->notifications = $notifications;
        $this->incidents = $incidents;
        $this->thresholds = $thresholds;
        $this->config = $config;
    }

    public function sendCriticalAlert(array $data): void 
    {
        try {
            $incident = $this->incidents->createIncident($data);
            
            $notification = new SecurityNotification(
                'critical',
                $this->formatAlertMessage($data),
                $this->enrichAlertData($data),
                $incident->getId()
            );

            $this->dispatchHighPriorityAlert($notification);
            $this->triggerEmergencyProtocols($incident);
            $this->monitorIncidentResolution($incident);

        } catch (\Exception $e) {
            $this->handleAlertFailure($e, $data);
        }
    }

    public function sendSecurityAlert(array $data): void 
    {
        try {
            if ($this->thresholds->exceedsThreshold($data)) {
                $this->escalateToHighPriority($data);
                return;
            }

            $notification = new SecurityNotification(
                'security',
                $this->formatAlertMessage($data),
                $this->enrichAlertData($data)
            );

            $this->dispatchStandardAlert($notification);
            $this->trackSecurityEvent($data);

        } catch (\Exception $e) {
            $this->handleAlertFailure($e, $data);
        }
    }

    public function sendFailureAlert(array $data): void 
    {
        try {
            $severity = $this->determineSeverity($data);
            
            if ($severity === 'critical') {
                $this->sendCriticalAlert($data);
                return;
            }

            $notification = new SecurityNotification(
                'failure',
                $this->formatAlertMessage($data),
                $this->enrichAlertData($data)
            );

            $this->dispatchFailureAlert($notification);
            $this->trackFailureEvent($data);

        } catch (\Exception $e) {
            $this->handleAlertFailure($e, $data);
        }
    }

    public function sendSystemAlert(array $data): void 
    {
        try {
            if ($this->isSystemCritical($data)) {
                $this->triggerSystemEmergency($data);
                return;
            }

            $notification = new SecurityNotification(
                'system',
                $this->formatAlertMessage($data),
                $this->enrichAlertData($data)
            );

            $this->dispatchSystemAlert($notification);
            $this->monitorSystemHealth($data);

        } catch (\Exception $e) {
            $this->handleAlertFailure($e, $data);
        }
    }

    private function dispatchHighPriorityAlert(SecurityNotification $notification): void 
    {
        $sent = false;
        $attempts = 0;

        while (!$sent && $attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                $this->notifications->sendUrgent(
                    $notification,
                    $this->config['critical_channels'],
                    self::CRITICAL_ALERT_TIMEOUT
                );
                $sent = true;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
                usleep(100000 * $attempts); // Exponential backoff
            }
        }
    }

    private function dispatchStandardAlert(SecurityNotification $notification): void 
    {
        $this->notifications->send(
            $notification,
            $this->config['standard_channels']
        );
    }

    private function dispatchFailureAlert(SecurityNotification $notification): void 
    {
        $this->notifications->send(
            $notification,
            $this->config['failure_channels'],
            ['retry' => true]
        );
    }

    private function dispatchSystemAlert(SecurityNotification $notification): void 
    {
        $this->notifications->send(
            $notification,
            $this->config['system_channels']
        );
    }

    private function triggerEmergencyProtocols(SecurityIncident $incident): void 
    {
        $this->incidents->activateEmergencyProcedures($incident);
        $this->notifyEmergencyContacts($incident);
        $this->initiateIncidentResponse($incident);
    }

    private function triggerSystemEmergency(array $data): void 
    {
        $incident = $this->incidents->createSystemIncident($data);
        $this->activateSystemRecovery($incident);
        $this->notifySystemTeam($incident);
    }

    private function monitorIncidentResolution(SecurityIncident $incident): void 
    {
        $this->incidents->monitorResolution(
            $incident,
            $this->config['resolution_timeout'],
            function($status) use ($incident) {
                $this->handleResolutionStatus($status, $incident);
            }
        );
    }

    private function monitorSystemHealth(array $data): void 
    {
        $metrics = $this->extractSystemMetrics($data);
        $this->thresholds->trackSystemMetrics($metrics);
    }

    private function enrichAlertData(array $data): array 
    {
        return array_merge($data, [
            'timestamp' => microtime(true),
            'environment' => $this->config['environment'],
            'system_state' => $this->captureSystemState(),
            'alert_id' => $this->generateAlertId()
        ]);
    }

    private function formatAlertMessage(array $data): string 
    {
        $template = $this->getMessageTemplate($data['type']);
        return $this->renderTemplate($template, $data);
    }

    private function determineSeverity(array $data): string 
    {
        return $this->thresholds->calculateSeverity($data);
    }

    private function isSystemCritical(array $data): bool 
    {
        return $this->thresholds->isSystemCritical($data);
    }

    private function handleAlertFailure(\Exception $e, array $data): void 
    {
        Log::emergency('Alert system failure', [
            'error' => $e->getMessage(),
            'data' => $data,
            'trace' => $e->getTraceAsString()
        ]);

        try {
            $this->executeEmergencyAlert($e, $data);
        } catch (\Exception $emergencyError) {
            // Last resort logging
            error_log('CRITICAL: Emergency alert system failure');
        }
    }

    private function captureSystemState(): array 
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }

    private function generateAlertId(): string 
    {
        return sprintf(
            '%s-%s',
            date('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }
}
