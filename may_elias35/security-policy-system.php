<?php

namespace App\Core\Security;

use App\Core\Monitoring\SystemMonitor;

class SecurityPolicyManager implements SecurityPolicyInterface
{
    private SystemMonitor $monitor;
    private array $config;
    private array $activePolicies = [];

    public function __construct(
        SystemMonitor $monitor,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->config = $config;
        $this->loadPolicies();
    }

    public function enforcePolicy(string $policyName, array $context): bool
    {
        $monitoringId = $this->monitor->startOperation('policy_enforcement');

        try {
            if (!isset($this->activePolicies[$policyName])) {
                throw new PolicyException("Unknown policy: {$policyName}");
            }

            $policy = $this->activePolicies[$policyName];
            return $this->evaluatePolicy($policy, $context);

        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validatePolicies(array $policies): bool
    {
        $monitoringId = $this->monitor->startOperation('policy_validation');

        try {
            foreach ($policies as $policy) {
                if (!$this->isValidPolicy($policy)) {
                    return false;
                }
            }
            return true;

        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function evaluatePolicy(array $policy, array $context): bool
    {
        foreach ($policy['rules'] as $rule) {
            if (!$this->evaluateRule($rule, $context)) {
                $this->handlePolicyViolation($policy, $rule, $context);
                return false;
            }
        }
        return true;
    }

    private function evaluateRule(array $rule, array $context): bool
    {
        return match($rule['type']) {
            'permission' => $this->checkPermission($rule, $context),
            'condition' => $this->evaluateCondition($rule, $context),
            'restriction' => $this->checkRestriction($rule, $context),
            default => false
        };
    }

    private function handlePolicyViolation(array $policy, array $rule, array $context): void
    {
        $this->monitor->recordPolicyViolation([
            'policy' => $policy['name'],
            'rule' => $rule['name'],
            'context' => $context,
            'timestamp' => microtime(true)
        ]);

        if ($policy['alert_on_violation']) {
            $this->triggerPolicyAlert($policy, $rule, $context);
        }
    }

    private function loadPolicies(): void
    {
        foreach ($this->config['policies'] as $name => $policy) {
            if ($this->isValidPolicy($policy)) {
                $this->activePolicies[$name] = $policy;
            }
        }
    }

    private function isValidPolicy(array $policy): bool
    {
        return isset($policy['name']) &&
               isset($policy['rules']) &&
               is_array($policy['rules']);
    }

    private function triggerPolicyAlert(array $policy, array $rule, array $context): void
    {
        $alert = [
            'type' => 'policy_violation',
            'policy' => $policy['name'],
            'rule' => $rule['name'],
            'context' => $context,
            'severity' => $policy['severity'] ?? 'high',
            'timestamp' => microtime(true)
        ];

        $this->monitor->triggerAlert($alert);
    }
}
