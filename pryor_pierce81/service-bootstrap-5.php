<?php

namespace App\Core;

class Bootstrap {
    public function boot(): void {
        // Core services
        $this->container->singleton(SecurityCore::class);
        $this->container->singleton(ContentCore::class);
        $this->container->singleton(StorageCore::class);
        
        // Base configurations
        $this->loadConfigurations();
        
        // Critical middleware
        $this->registerMiddleware([
            SecurityMiddleware::class,
            ValidationMiddleware::class,
            MonitoringMiddleware::class
        ]);
    }

    private function loadConfigurations(): void {
        $config = require('rapid-deployment.php');
        $this->container->instance('config', $config);
    }

    private function registerMiddleware(array $middleware): void {
        foreach($middleware as $m) {
            $this->app->middleware->register($m);
        }
    }
}
