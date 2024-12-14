<?php

namespace App\Core\Security;

use App\Core\Infrastructure\InfrastructureManager;
use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Exceptions\{SecurityException, HardeningException};

class SystemHardening
{
    private SecurityManager $security;
    private InfrastructureManager $infrastructure;
    private IntrusionDetection $ids;
    private FirewallManager $firewall;
    private SecurityAudit $audit;

    public function __construct(
        SecurityManager $security,
        InfrastructureManager $infrastructure,
        IntrusionDetection $ids,
        FirewallManager $firewall,
        SecurityAudit $audit
    ) {
        $this->security = $security;
        $this->infrastructure = $infrastructure;
        $this->ids = $ids;
        $this->firewall = $firewall;
        $this->audit = $audit;
    }

    /**
     * Apply critical system hardening with security validation
     */
    public function hardenSystem(): HardeningResult
    {
        return $this->security->executeCriticalOperation(function() {
            try {
                // Verify system state
                $this->verifySystemState();
                
                // Apply security hardening
                $this->applySecurityHardening();
                
                // Configure intrusion detection
                $this->configureIDS();
                
                // Setup firewall rules
                $this->configureFirewall();
                
                // Verify hardening
                return $this->verifyHardening();
                
            } catch (\Exception $e) {
                throw new HardeningException('System hardening failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Real-time intrusion detection and prevention
     */
    public function enableIntrusionPrevention(): void
    {
        $this->security->executeCriticalOperation(function() {
            // Configure IDS/IPS
            $this->ids->configure([
                'mode' => 'prevention',
                'sensitivity' => 'high',
                'auto_block' => true
            ]);

            // Setup real-time monitoring
            $this->ids->enableRealTimeMonitoring([
                'request_analysis' => true,
                'behavior_analysis' => true,
                'anomaly_detection' => true
            ]);

            // Configure automated responses
            $this->configureAutomatedResponses();
        });
    }

    /**
     * Security audit and compliance verification
     */
    public function performSecurityAudit(): AuditResult
    {
        return $this->security->executeCriticalOperation(function() {
            // Perform comprehensive audit
            $result = $this->audit->performAudit([
                'configuration' => true,
                'permissions' => true,
                'vulnerabilities' => true,
                'compliance' => true
            ]);

            // Handle critical findings
            if ($result->hasCriticalFindings()) {
                $this->handleCriticalFindings($result->getCriticalFindings());
            }

            return $result;
        });
    }

    private function verifySystemState(): void
    {
        // Check security components
        if (!$this->security->isFullyOperational()) {
            throw new SecurityException('Security system not fully operational');
        }

        // Verify infrastructure
        if (!$this->infrastructure->isHealthy()) {
            throw new SecurityException('Infrastructure health check failed');
        }

        // Check database security
        $this->verifyDatabaseSecurity();

        // Verify file permissions
        $this->verifyFilePermissions();
    }

    private function applySecurityHardening(): void
    {
        // Secure PHP configuration
        $this->hardenPhpConfiguration();

        // Secure database configuration
        $this->hardenDatabaseConfiguration();

        // Apply filesystem restrictions
        $this->hardenFilesystem();

        // Configure security headers
        $this->configureSecurityHeaders();
    }

    private function configureIDS(): void
    {
        $this->ids->configure([
            'rules' => [
                'sql_injection' => [
                    'enabled' => true,
                    'sensitivity' => 'high',
                    'action' => 'block'
                ],
                'xss_attacks' => [
                    'enabled' => true,
                    'sensitivity' => 'high',
                    'action' => 'block'
                ],
                'path_traversal' => [
                    'enabled' => true,
                    'sensitivity' => 'high',
                    'action' => 'block'
                ],
                'request_anomalies' => [
                    'enabled' => true,
                    'sensitivity' => 'medium',
                    'action' => 'log'
                ]
            ],
            'monitoring' => [
                'log_all_requests' => true,
                'track_ip_reputation' => true,
                'behavioral_analysis' => true
            ],
            'responses' => [
                'auto_ban_threshold' => 5,
                'ban_duration' => 3600,
                'notify_admin' => true
            ]
        ]);
    }

    private function configureFirewall(): void
    {
        $this->firewall->configure([
            'default_policy' => 'deny',
            'allowed_ips' => $this->getAllowedIps(),
            'rate_limiting' => [
                'enabled' => true,
                'max_requests' => 100,
                'window' => 60
            ],
            'rules' => [
                [
                    'path' => '/api/*',
                    'methods' => ['POST', 'PUT', 'DELETE'],
                    'require_auth' => true
                ],
                [
                    'path' => '/admin/*',
                    'ip_whitelist' => true,
                    'require_auth' => true
                ]
            ]
        ]);
    }

    private function configureAutomatedResponses(): void
    {
        $this->ids->configureResponses([
            'block_ip' => [
                'threshold' => 5,
                'duration' => 3600
            ],
            'increase_monitoring' => [
                'threshold' => 3,
                'duration' => 7200
            ],
            'alert_admin' => [
                'threshold' => 1,
                'methods' => ['email', 'sms']
            ]
        ]);
    }

    private function hardenPhpConfiguration(): void
    {
        $criticalSettings = [
            'display_errors' => 'Off',
            'expose_php' => 'Off',
            'disable_functions' => 'exec,passthru,shell_exec,system,proc_open,popen',
            'allow_url_fopen' => 'Off',
            'allow_url_include' => 'Off',
            'session.cookie_httponly' => 'On',
            'session.cookie_secure' => 'On',
            'session.use_strict_mode' => 'On'
        ];

        foreach ($criticalSettings as $key => $value) {
            if (!ini_set($key, $value)) {
                throw new HardeningException("Failed to set PHP configuration: $key");
            }
        }
    }

    private function hardenDatabaseConfiguration(): void
    {
        DB::statement("REVOKE ALL PRIVILEGES ON *.* FROM 'app_user'@'localhost'");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON cms.* TO 'app_user'@'localhost'");
        DB::statement("FLUSH PRIVILEGES");
    }

    private function hardenFilesystem(): void
    {
        $paths = [
            storage_path(),
            base_path('.env'),
            config_path(),
            database_path()
        ];

        foreach ($paths as $path) {
            $this->secureFilePath($path);
        }
    }

    private function configureSecurityHeaders(): void
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => $this->getCSPPolicy(),
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()'
        ];

        config(['secure_headers' => $headers]);
    }

    private function getCSPPolicy(): string
    {
        return "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
               "style-src 'self' 'unsafe-inline'; " .
               "img-src 'self' data: https:; " .
               "font-src 'self'; " .
               "connect-src 'self'; " .
               "media-src 'self'; " .
               "object-src 'none'; " .
               "base-uri 'self'; " .
               "form-action 'self';";
    }

    private function verifyHardening(): HardeningResult
    {
        $checks = [
            'security_components' => $this->security->isFullyOperational(),
            'infrastructure' => $this->infrastructure->isHealthy(),
            'ids' => $this->ids->isOperational(),
            'firewall' => $this->firewall->isActive(),
            'database_security' => $this->verifyDatabaseSecurity(),
            'filesystem_security' => $this->verifyFilePermissions(),
            'configuration_security' => $this->verifySecurityConfiguration()
        ];

        foreach ($checks as $check => $result) {
            if (!$result) {
                throw new HardeningException("Hardening verification failed: $check");
            }
        }

        return new HardeningResult($checks);
    }
}
