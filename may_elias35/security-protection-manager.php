<?php

namespace App\Core\Protection;

use App\Core\Security\SecurityContext;
use App\Core\Security\CriticalOperation;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Notification\AlertManager;

class ProtectionManager implements ProtectionInterface
{
    private SecurityConfig $config;
    private SystemMonitor $monitor;
    private AlertManager $alertManager;
    private array $activeProtections = [];

    public function __construct(
        SecurityConfig $config,
        SystemMonitor $monitor,
        AlertManager $alertManager
    ) {
        $this->config = $config;
        $this->monitor = $monitor;
        $this->alertManager = $alertManager;
    }

    public function startProtection(CriticalOperation $operation): void
    {
        $protection = [
            'operation_id' => $operation->getId(),
            'start_time' => microtime(true),
            'monitoring_id' => $this->monitor->startOperation($operation),
            'protection_level' => $this->calculateProtectionLevel($operation)
        ];

        $this->activeProtections[$operation->getId()] = $protection;
        
        $this->monitor->enableEnhancedMonitoring($operation);
    }

    public function endProtection(CriticalOperation $operation): void
    {
        $protectionId = $operation->getId();
        
        if (isset($this->activeProtections[$protectionId])) {
            $protection = $this->activeProtections[$protectionId];
            
            $this->monitor->endOperation($protection['monitoring_id']);
            
            unset($this->activeProtections[$protectionId]);
        }
    }

    public function checkPermissions(
        SecurityContext $context,
        CriticalOperation $operation
    ): bool {
        $requiredPermissions = $operation->getRequiredPermissions();
        return $this->validatePermissions($context, $requiredPermissions);
    }

    public function checkRateLimit(SecurityContext $context): bool
    {
        $limits = $this->config->getRateLimits($context->getLevel());
        return $this->validateRateLimit($context, $limits);
    }

    public function handleFailure(
        CriticalOperation $operation,
        \Exception $e
    ): void {
        $this->monitor->recordFailure($operation, $e);
        
        $this->alertManager->triggerAlert(
            'protection_failure',
            [
                'operation' => $operation->getId(),
                'error' => $e->getMessage()
            ]
        );

        if ($this->isSystemThreat($e)) {
            $this->initiateEmergencyProtocol($operation);
        }
    }

    private function calculateProtectionLevel(CriticalOperation $operation): int
    {
        $baseLevel = $this->config->getBaseProtectionLevel();
        $operationRisk = $operation->getRiskLevel();
        $systemLoad = $this->monitor->getSystemLoad();
        
        return min(
            100,
            $baseLevel + $operationRisk + ($systemLoad / 10)
        );
    }

    private function validatePermissions(
        SecurityContext $context,
        array $required
    ): bool {
        foreach ($required as $permission) {
            if (!$context->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    private function validateRateLimit(
        SecurityContext $context,
        array $limits
    ): bool {
        $key = $this->getRateLimitKey($context);
        $count = cache()->increment($key);
        
        if ($count === 1) {
            cache()->expire($key, $limits['window']);
        }
        
        return $count <= $limits['max_requests'];
    }

    private function isSystemThreat(\Exception $e): bool
    {
        return $e instanceof SecurityThreatException ||
               $e instanceof SystemIntegrityException;
    }

    private function initiateEmergencyProtocol(CriticalOperation $operation): void
    {
        $this->alertManager->triggerEmergencyAlert($operation);
        $this->monitor->enableCriticalMonitoring();
        
        // Additional emergency measures
        if ($this->config->get('emergency.system_lockdown')) {
            $this->initiateSystemLockdown();
        }
    }
}
