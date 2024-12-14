// config/security.php
<?php

return [
    'validation' => [
        'strict_mode' => true,
        'input_sanitization' => true,
        'output_encoding' => true,
    ],

    'encryption' => [
        'algorithm' => 'AES-256-GCM',
        'key_rotation' => 'daily',
        'secure_key_storage' => true
    ],

    'monitoring' => [
        'real_time' => true,
        'metrics_collection' => true,
        'alert_threshold' => [
            'cpu_usage' => 70,
            'memory_usage' => 80,
            'disk_space' => 20,
            'response_time' => 200
        ]
    ],

    'audit' => [
        'detailed_logging' => true,
        'security_events' => true,
        'performance_metrics' => true,
        'user_actions' => true
    ],

    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
        'security_tags' => true
    ]
];

// config/cms.php
<?php

return [
    'content' => [
        'validation' => [
            'required_fields' => [
                'title',
                'content',
                'status'
            ],
            'max_sizes' => [
                'title' => 200,
                'content' => 65535
            ]
        ],
        'security' => [
            'sanitization' => true,
            'xss_protection' => true,
            'sql_injection_prevention' => true
        ],
        'cache' => [
            'enabled' => true,
            'ttl' => 1800
        ]
    ],

    'media' => [
        'allowed_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf'
        ],
        'max_size' => 10485760, // 10MB
        'secure_storage' => true
    ],

    'api' => [
        'rate_limit' => [
            'enabled' => true,
            'max_attempts' => 60,
            'decay_minutes' => 1
        ],
        'security' => [
            'requires_authentication' => true,
            'token_expiry' => 3600
        ]
    ]
];

// tests/Unit/Security/CoreSecurityManagerTest.php
<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Core\Security\CoreSecurityManager;
use App\Core\Exceptions\SecurityException;

class CoreSecurityManagerTest extends TestCase
{
    private CoreSecurityManager $security;

    protected function setUp(): void
    {
        parent::setUp();
        $this->security = app(CoreSecurityManager::class);
    }

    public function test_validates_secure_operation()
    {
        $params = ['action' => 'test', 'data' => 'secure_data'];
        
        $result = $this->security->validateSecureOperation($params, 'test_operation');
        
        $this->assertTrue($result);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'test_operation',
            'status' => 'success'
        ]);
    }

    public function test_encrypts_sensitive_data()
    {
        $data = ['sensitive' => 'test_data'];
        
        $encrypted = $this->security->encryptSensitiveData($data);
        
        $this->assertNotEquals($data['sensitive'], $encrypted['sensitive']);
        $this->assertTrue(strlen($encrypted['sensitive']) > strlen($data['sensitive']));
    }

    public function test_verifies_security_compliance()
    {
        $compliance = $this->security->verifySecurityCompliance();
        
        $this->assertArrayHasKey('validation', $compliance);
        $this->assertArrayHasKey('encryption', $compliance);
        $this->assertArrayHasKey('audit', $compliance);
        $this->assertArrayHasKey('cache', $compliance);
        
        $this->assertTrue($compliance['validation']);
        $this->assertTrue($compliance['encryption']);
        $this->assertTrue($compliance['audit']);
        $this->assertTrue($compliance['cache']);
    }
}

// tests/Unit/CMS/ContentManagerTest.php
<?php

namespace Tests\Unit\CMS;

use Tests\TestCase;
use App\Core\CMS\ContentManager;
use App\Core\CMS\Models\Content;
use App\Core\Exceptions\ContentCreationException;

class ContentManagerTest extends TestCase
{
    private ContentManager $contentManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentManager = app(ContentManager::class);
    }

    public function test_creates_content_with_security_validation()
    {
        $data = [
            'title' => 'Test Content',
            'content' => 'Secure content data',
            'status' => 'draft'
        ];
        
        $content = $this->contentManager->createContent($data);
        
        $this->assertInstanceOf(Content::class, $content);
        $this->assertEquals($data['title'], $content->title);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'content_creation',
            'status' => 'success'
        ]);
    }

    public function test_updates_content_securely()
    {
        $content = Content::factory()->create();
        $updateData = ['title' => 'Updated Title'];
        
        $updated = $this->contentManager->updateContent($content->id, $updateData);
        
        $this->assertEquals('Updated Title', $updated->title);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'content_update',
            'status' => 'success'
        ]);
    }

    public function test_fails_content_creation_with_invalid_data()
    {
        $this->expectException(ContentCreationException::class);
        
        $this->contentManager->createContent([]);
    }
}