<?php

namespace App\Providers;

class CMSServiceProvider extends ServiceProvider
{
    protected ValidationChain $validator;
    protected SecurityEnforcer $security;
    protected PerformanceMonitor $monitor;

    public function register(): void
    {
        $this->app->singleton(CMSInterface::class, function($app) {
            return new CriticalCMSManager(
                $app->make(SecurityEnforcer::class),
                $app->make(ValidationChain::class),
                $app->make(PerformanceMonitor::class),
                $app->make(CacheManager::class),
                $app->make(EventDispatcher::class)
            );
        });

        $this->app->singleton(SecurityEnforcer::class, function($app) {
            return new SecurityEnforcer(
                $app->make(ValidationService::class),
                $app->make(EncryptionService::class),
                $app->make(AuditLogger::class)
            );
        });

        $this->app->singleton(ValidationChain::class, function($app) {
            return new ValidationChain([
                $app->make(SecurityValidator::class),
                $app->make(InputValidator::class),
                $app->make(BusinessRuleValidator::class),
                $app->make(IntegrityValidator::class)
            ]);
        });
    }

    public function boot(): void
    {
        $this->bootSecurityEnforcement();
        $this->bootValidationRules();
        $this->bootPerformanceMonitoring();
    }

    protected function bootSecurityEnforcement(): void
    {
        $this->security->enforceRules([
            'authentication' => true,
            'authorization' => true,
            'encryption' => true,
            'audit' => true
        ]);
    }

    protected function bootValidationRules(): void
    {
        $this->validator->registerRules([
            'input' => $this->getInputRules(),
            'business' => $this->getBusinessRules(),
            'security' => $this->getSecurityRules()
        ]);
    }

    protected function bootPerformanceMonitoring(): void
    {
        $this->monitor->initializeTracking([
            'response_time' => true,
            'memory_usage' => true,
            'query_performance' => true,
            'cache_efficiency' => true
        ]);
    }
}