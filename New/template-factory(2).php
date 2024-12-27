<?php

namespace App\Core\Template\Factory;

use App\Core\Template\Compilation\{
    EnhancedTemplateCompiler,
    TemplateValidator,
    TemplateCacheManager,
    TemplatePerformanceMonitor
};
use App\Core\Template\Validation\{
    TemplateValidationManager,
    ValidationRule
};
use App\Core\Template\ErrorHandling\{
    TemplateErrorHandler,
    ErrorRecoveryService
};
use App\Core\Security\SecurityManagerInterface;

class TemplateEngineFactory
{
    private SecurityManagerInterface $security;
    private \PDO $db;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        \PDO $db,
        array $config
    ) {
        $this->security = $security;
        $this->db = $db;
        $this->config = $config;
    }

    public function create(): EnhancedTemplateCompiler
    {
        $validator = $this->createValidator();
        $cache = $this->createCacheManager();
        $monitor = $this->createPerformanceMonitor();
        $errorHandler = $this->createErrorHandler();
        
        return new EnhancedTemplateCompiler(
            $this->security,
            $this->config['compile_path'],
            $validator,
            $cache,
            $monitor
        );
    }

    private function createValidator(): TemplateValidator
    {
        $validator = new TemplateValidator();
        $validationManager = new TemplateValidationManager();
        
        foreach ($validationManager->getDefaultRules() as $rule) {
            $validator->addRule($rule->getName(), fn($content) => $rule->validate($content));
        }
        
        return $validator;
    }

    private function createCacheManager(): TemplateCacheManager
    {
        return new TemplateCacheManager(
            $this->config['cache_path'],
            $this->config['cache_lifetime'] ?? 3600
        );
    }

    private function createPerform