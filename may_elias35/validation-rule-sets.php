```php
namespace App\Core\Security\Validation\Rules;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Monitoring\MetricsSystem;
use App\Core\Security\Patterns\Context\ValidationContext;

class ValidationRuleSet
{
    private SecurityManager $security;
    private MetricsSystem $metrics;
    private AuditLogger $auditLogger;

    public function getSecurityRules(): array
    {
        return [
            'authentication' => [
                'multi_factor' => [
                    'required' => true,
                    'strength' => 'high',
                    'timeout' => 900 // 15 minutes
                ],
                'session' => [
                    'encryption' => 'required',
                    'rotation' => true,
                    'max_lifetime' => 3600
                ],
                'access_control' => [
                    'strict' => true,
                    'role_based' => true,
                    'audit_logging' => true
                ]
            ],
            'data_protection' => [
                'encryption' => [
                    'algorithm' => 'AES-256-GCM',
                    'key_rotation' => true,
                    'secure_storage' => true
                ],
                'integrity' => [
                    'checksum_verification' => true,
                    'tamper_detection' => true,
                    'audit_trail' => true
                ],
                'validation' => [
                    'input_sanitization' => true,
                    'output_encoding' => true,
                    'type_checking' => true
                ]
            ],
            'monitoring' => [
                'realtime' => [
                    'enabled' => true,
                    'interval' => 1, // 1 second
                    'alert_threshold' => 3
                ],
                'audit' => [
                    'comprehensive' => true,
                    'secure_storage' => true,
                    'retention_period' => 90 // days
                ],
                'alerts' => [
                    'immediate_notification' => true,
                    'escalation_protocol' => true,
                    'incident_tracking' => true
                ]
            ]
        ];
    }

    public function getPerformanceRules(): array
    {
        return [
            'response_time' => [
                'api_calls' => [
                    'threshold' => 100, // milliseconds
                    'max_allowed' => 200,
                    'monitoring' => true
                ],
                'database' => [
                    'threshold' => 50, // milliseconds
                    'max_allowed' => 100,
                    'optimization_required' => true
                ],
                'cache' => [
                    'threshold' => 10, // milliseconds
                    'hit_ratio' => 0.90,
                    'monitoring' => true
                ]
            ],
            'resource_usage' => [
                'cpu' => [
                    'threshold' => 70, // percentage
                    'critical' => 90,
                    'monitoring' => true
                ],
                'memory' => [
                    'threshold' => 80, // percentage
                    'critical' => 95,
                    'monitoring' => true
                ],
                'storage' => [
                    'threshold' => 85, // percentage
                    'critical' => 95,
                    'monitoring' => true
                ]
            ],
            'optimization' => [
                'caching' => [
                    'required' => true,
                    'strategy' => 'multi_layer',
                    'monitoring' => true
                ],
                'queries' => [
                    'optimization_required' => true,
                    'max_execution_time' => 1000,
                    'monitoring' => true
                ],
                'connections' => [
                    'pooling' => true,
                    'max_connections' => 100,
                    'timeout' => 5
                ]
            ]
        ];
    }

    public function getComplianceRules(): array
    {
        return [
            'data_handling' => [
                'encryption' => [
                    'required' => true,
                    'standards' => ['FIPS-140-2', 'PCI-DSS'],
                    'validation' => true
                ],
                'privacy' => [
                    'gdpr_compliance' => true,
                    'data_minimization' => true,
                    'consent_management' => true
                ],
                'retention' => [
                    'policy_enforcement' => true,
                    'secure_deletion' => true,
                    'audit_trail' => true
                ]
            ],
            'audit' => [
                'logging' => [
                    'comprehensive' => true,
                    'tamper_proof' => true,
                    'retention_period' => 365
                ],
                'monitoring' => [
                    'continuous' => true,
                    'automated_alerts' => true,
                    'incident_tracking' => true
                ],
                'reporting' => [
                    'automated' => true,
                    'schedule' => 'daily',
                    'retention' => 'permanent'
                ]
            ]
        ];
    }

    public function validateAgainstRules(ValidationContext $context): ValidationResult
    {
        DB::beginTransaction();
        
        try {
            // Security validation
            $this->validateSecurity($context);
            
            // Performance validation
            $this->validatePerformance($context);
            
            // Compliance validation
            $this->validateCompliance($context);
            
            $result = new ValidationResult([
                'context' => $context,
                'timestamp' => now(),
                'validated_by' => $this->security->getCurrentUser()
            ]);
            
            DB::commit();
            $this->auditLogger->logValidation($result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $context);
            throw $e;
        }
    }

    private function validateSecurity(ValidationContext $context): void
    {
        foreach ($this->getSecurityRules() as $category => $rules) {
            if (!$this->validateRuleCategory($context, $category, $rules)) {
                throw new SecurityValidationException(
                    "Security validation failed for category: $category"
                );
            }
        }
    }
}
```
