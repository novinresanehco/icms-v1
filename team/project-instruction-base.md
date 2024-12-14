# SET PROJECT INSTRUCTIONS - CRITICAL FOUNDATION

## 1. FOUNDATIONAL PRINCIPLES

### 1.1 Project Governance
```plaintext
governance/
├── core/
│   ├── principles/           # اصول بنیادین پروژه
│   ├── responsibilities/     # مسئولیت‌های کلیدی
│   └── compliance/          # الزامات انطباق
│
├── security/
│   ├── protocols/           # پروتکل‌های امنیتی
│   └── validations/        # اعتبارسنجی‌ها
│
└── quality/
    ├── standards/          # استانداردهای کیفی
    └── metrics/           # معیارهای سنجش
```

### 1.2 Core Development Standards
```php
/**
 * CRITICAL: Core Development Standards
 * These standards are MANDATORY and non-negotiable
 */
interface CoreStandards 
{
    /**
     * Security First Approach
     * - Every component must pass security validation
     * - No exceptions to security protocols
     * - Continuous security monitoring
     */
    public function validateSecurity(): SecurityValidation;
    
    /**
     * Quality Assurance
     * - 100% test coverage for critical paths
     * - Comprehensive documentation
     * - Peer review requirements
     */
    public function validateQuality(): QualityValidation;
    
    /**
     * Performance Requirements
     * - Response time < 100ms
     * - Resource optimization
     * - Scalability requirements
     */
    public function validatePerformance(): PerformanceMetrics;
}
```

### 1.3 Critical Success Factors
```yaml
critical_factors:
  security:
    - All data must be encrypted at rest and in transit
    - Access control at all levels
    - Complete audit trail for all operations
    
  reliability:
    - 99.99% uptime requirement
    - Automatic failover capabilities
    - Comprehensive disaster recovery
    
  scalability:
    - Horizontal scaling capability
    - Resource optimization
    - Performance monitoring
```

## 2. MANDATORY IMPLEMENTATION GUIDELINES

### 2.1 Development Protocol
```php
namespace Project\Core\Protocol;

class DevelopmentProtocol 
{
    /**
     * Every feature MUST follow this protocol
     */
    public function validateImplementation(Feature $feature): ValidationResult 
    {
        // 1. Security Validation
        $this->validateSecurity($feature);
        
        // 2. Quality Checks
        $this->validateQuality($feature);
        
        // 3. Performance Verification
        $this->validatePerformance($feature);
        
        // 4. Documentation Requirements
        $this->validateDocumentation($feature);
        
        // 5. Integration Tests
        $this->validateIntegration($feature);
    }
}
```

### 2.2 Security Requirements
```php
class SecurityRequirements 
{
    private const CRITICAL_REQUIREMENTS = [
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation' => true,
            'storage' => 'secure_enclave'
        ],
        'authentication' => [
            'multi_factor' => true,
            'password_policy' => 'high_strength',
            'session_management' => 'strict'
        ],
        'authorization' => [
            'role_based' => true,
            'granular_permissions' => true,
            'audit_logging' => true
        ]
    ];

    public function enforce(): void 
    {
        foreach (self::CRITICAL_REQUIREMENTS as $domain => $requirements) {
            $this->enforceRequirements($domain, $requirements);
        }
    }
}
```

[ادامه مستندات در صفحه بعدی...]

این بخش اول از مستندات بنیادین است. آیا مایلید در صفحه جدید با جزئیات بیشتر ادامه دهیم؟