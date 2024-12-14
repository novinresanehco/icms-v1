<?php

namespace App\Console\Commands;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Console\Command;

class SecurityAuditCommand extends Command
{
    protected $signature = 'security:audit 
        {--type=full : Type of audit to perform}
        {--detail=normal : Level of detail in report}';

    protected $description = 'Perform security audit of the system';

    private SecurityManager $security;
    private SystemMonitor $monitor;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor
    ) {
        parent::__construct();
        
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function handle()
    {
        $monitoringId = $this->monitor->startOperation('security_audit');
        
        try {
            $type = $this->option('type');
            $detail = $this->option('detail');
            
            $this->info('Starting security audit...');
            
            $results = match($type) {
                'full' => $this->performFullAudit(),
                'quick' => $this->performQuickAudit(),
                'focused' => $this->performFocusedAudit(),
                default => throw new \InvalidArgumentException('Invalid audit type')
            };
            
            $this->generateReport($results, $detail);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->error('Audit failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function performFullAudit(): array
    {
        $this->info('Performing full security audit...');
        
        return [
            'configuration' => $this->auditConfiguration(),
            'permissions' => $this->auditPermissions(),
            'encryption' => $this->auditEncryption(),
            'authentication' => $this->auditAuthentication(),
            'logging' => $this->auditLogging(),
            'vulnerabilities' => $this->auditVulnerabilities()
        ];
    }

    private function auditConfiguration(): array
    {
        $this->info('Auditing security configuration...');
        
        $issues = [];
        $config = config('security');
        
        // Check required settings
        foreach ($this->getRequiredSettings() as $setting => $expected) {
            if (!isset($config[$setting]) || $config[$setting] !== $expected) {
                $issues[] = "Invalid setting: {$setting}";
            }
        }
        
        // Check security policies
        foreach ($config['policies'] as $policy => $settings) {
            if (!$this->validatePolicy($policy, $settings)) {
                $issues[] = "Invalid policy: {$policy}";
            }
        }
        
        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues
        ];
    }

    private function auditPermissions(): array
    {
        $this->info('Auditing permissions...');
        
        $issues = [];
        
        // Check role definitions
        foreach ($this->getRoles() as $role) {
            if (!$this->validateRole($role)) {
                $issues[] = "Invalid role definition: {$role}";
            }
        }
        
        // Check permission assignments
        foreach ($this->getPermissionAssignments() as $assignment) {
            if (!$this->validatePermissionAssignment($assignment)) {
                $issues[] = "Invalid permission assignment: {$assignment}";
            }
        }
        
        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues
        ];
    }

    private function generateReport(array $results, string $detail): void
    {
        $report = [
            'timestamp' => now(),
            'type' => $this->option('type'),
            'detail_level' => $detail,
            'results' => $results,
            'summary' => $this->generateSummary($results)
        ];

        if ($detail === 'full') {
            $report['recommendations'] = $this->generateRecommendations($results);
            $report['metrics'] = $this->monitor->getSecurityMetrics();
        }

        $this->saveReport($report);
        $this->displayResults($report);
    }

    private function saveReport(array $report): void
    {
        $filename = sprintf(
            'security-audit-%s.json',
            date('Y-m-d-H-i-s')
        );

        Storage::put(
            "security/audits/{$filename}",
            json_encode($report, JSON_PRETTY_PRINT)
        );
    }

    private function displayResults(array $report): void
    {
        $this->info('Security Audit Results:');
        
        foreach ($report['results'] as $category => $result) {
            $status = $result['status'] === 'pass' ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
            $this->line("{$category}: {$status}");
            
            if ($result['status'] === 'fail') {
                foreach ($result['issues'] as $issue) {
                    $this->error("  - {$issue}");
                }
            }
        }

        $this->line('');
        $this->info('Summary:');
        $this->line($report['summary']);
    }
}
