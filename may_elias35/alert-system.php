<?php
namespace App\Core\System;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\{SecurityManager, AuditLogger};
use App\Core\Exceptions\{AlertException, NotificationException};

class AlertSystem implements AlertSystemInterface
{
    private SecurityManager $security;
    private AuditLogger $audit;
    private NotificationService $notifier;
    private AlertRepository $repository;
    private ResponseCoordinator $coordinator;

    public function triggerAlert(AlertEvent $event, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $context) {
            $this->validateAlert($event);
            
            DB::transaction(function() use ($event, $context) {
                $alert = $this->repository->create([
                    'type' => $event->getType(),
                    'severity' => $event->getSeverity(),
                    'source' => $event->getSource(),
                    'data' => $this->serializeEventData($event),
                    'timestamp' => microtime(true)
                ]);
                
                $this->processAlert($alert, $context);
                $this->audit->logAlert($alert, $context);
                
                if ($alert->isCritical()) {
                    $this->handleCriticalAlert($alert, $context);
                }
            });
        }, $context);
    }

    public function notifyTeam(AlertNotification $notification, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(function() use ($notification, $context) {
            try {
                $this->notifier->sendNotification(
                    $this->getTargetTeam($notification),
                    $this->formatNotification($notification),
                    $this->getNotificationPriority($notification)
                );
                
                $this->audit->logNotification($notification, $context);
                
            } catch (\Throwable $e) {
                $this->handleNotificationFailure($e, $notification, $context);
                throw new NotificationException(
                    'Failed to send notification: ' . $e->getMessage(),
                    previous: $e
                );
            }
        }, $context);
    }

    public function handleSystemEvent(SystemEvent $event, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $context) {
            $alert = $this->createAlertFromEvent($event);
            
            if ($event->requiresImmediateAction()) {
                $this->coordinator->initiateEmergencyResponse($event);
                $this->notifyEmergencyTeam($event);
            }
            
            $this->processSystemEvent($event, $context);
            $this->audit->logSystemEvent($event, $context);
            
            if ($this->requiresEscalation($event)) {
                $this->escalateEvent($event, $context);
            }
        }, $context);
    }

    private function validateAlert(AlertEvent $event): void
    {
        if (!$event->isValid()) {
            throw new AlertException('Invalid alert event');
        }

        if ($this->isDuplicate($event)) {
            throw new AlertException('Duplicate alert detected');
        }
    }

    private function processAlert(Alert $alert, SecurityContext $context): void
    {
        $response = $this->coordinator->determineResponse($alert);
        
        if ($response->requiresAction()) {
            $this->executeResponse($response, $context);
        }
        
        $this->updateAlertStatus($alert, $response);
        $this->notifyRelevantTeams($alert, $response);
    }

    private function handleCriticalAlert(Alert $alert, SecurityContext $context): void
    {
        $this->coordinator->initiateCriticalResponse($alert);
        $this->notifier->sendCriticalNotification($alert);
        $this->audit->logCriticalAlert($alert, $context);
        
        if ($alert->requiresSystemAction()) {
            $this->executeSystemAction($alert, $context);
        }
    }

    private function processSystemEvent(SystemEvent $event, SecurityContext $context): void
    {
        $impact = $this->assessEventImpact($event);
        
        if ($impact->isCritical()) {
            $this->handleCriticalImpact($impact, $context);
        }
        
        $this->updateSystemStatus($event);
        $this->storeEventMetrics($event);
    }

    private function escalateEvent(SystemEvent $event, SecurityContext $context): void
    {
        $escalation = $this->coordinator->createEscalation($event);
        
        $this->notifier->sendEscalationNotification(
            $escalation->getTargetTeam(),
            $this->formatEscalation($escalation)
        );
        
        $this->audit->logEscalation($escalation, $context);
    }

    private function executeResponse(AlertResponse $response, SecurityContext $context): void
    {
        try {
            $this->coordinator->executeResponse($response);
            $this->audit->logResponse($response, $context);
            
        } catch (\Throwable $e) {
            $this->handleResponseFailure($e, $response, $context);
            throw $e;
        }
    }

    private function handleNotificationFailure(\Throwable $e, AlertNotification $notification, SecurityContext $context): void
    {
        $this->audit->logNotificationFailure($e, $notification, $context);
        
        if ($notification->isCritical()) {
            $this->activateBackupNotificationChannel($notification);
        }
    }
}
