// tests/Unit/Infrastructure/SystemMonitorTest.php
<?php

namespace Tests\Unit\Infrastructure;

use Tests\TestCase;
use App\Core\Infrastructure\SystemMonitor;
use App\Core\Exceptions\MonitoringException;

class SystemMonitorTest extends TestCase
{
    private SystemMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monitor = app(SystemMonitor::class);
    }

    public function test_monitors_system_health()
    {
        $health = $this->monitor->monitorSystemHealth();
        
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('metrics', $health);
        $this->assertArrayHasKey('alerts', $health);
        
        $this->assertArrayHasKey('cpu_usage', $health['metrics']);
        $this->assertArrayHasKey('memory_usage', $health['metrics']);
        $this->assertArrayHasKey('disk_space', $health['metrics']);
    }

    public function test_generates_alerts_for_high_resource_usage()
    {
        // Mock high CPU usage
        $this