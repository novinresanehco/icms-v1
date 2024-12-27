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

    private function createPerformanceMonitor(): TemplatePerformanceMonitor
    {
        return new TemplatePerformanceMonitor();
    }

    private function createErrorHandler(): TemplateErrorHandler
    {
        $monitor = new TemplateMonitoringService(
            $this->db,
            $this->config['environment'] ?? 'production'
        );
        
        $errorHandler = new TemplateErrorHandler(
            $monitor,
            $this->config['throw_on_error'] ?? true
        );
        
        $recoveryService = new ErrorRecoveryService(
            $monitor,
            $this->config['backup_path']
        );
        
        return $errorHandler;
    }
}

class TemplateCompilerBuilder
{
    private array $compilers = [];
    private array $directives = [];
    private array $validationRules = [];
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function addCompiler(string $name, callable $factory): self
    {
        $this->compilers[$name] = $factory;
        return $this;
    }

    public function addDirective(string $name, callable $handler): self
    {
        $this->directives[$name] = $handler;
        return $this;
    }

    public function addValidationRule(ValidationRule $rule): self
    {
        $this->validationRules[] = $rule;
        return $this;
    }

    public function build(SecurityManagerInterface $security, \PDO $db): EnhancedTemplateCompiler
    {
        $factory = new TemplateEngineFactory($security, $db, $this->config);
        $compiler = $factory->create();

        foreach ($this->compilers as $name => $factory) {
            $compiler->addCompiler($factory());
        }

        $directiveCompiler = new DirectiveCompiler();
        foreach ($this->directives as $name => $handler) {
            $directiveCompiler->addDirective($name, $handler);
        }
        $compiler->addCompiler($directiveCompiler);

        $validator = $compiler->getValidator();
        foreach ($this->validationRules as $rule) {
            $validator->addRule($rule);
        }

        return $compiler;
    }
}

class CompilerRegistry
{
    private static array $compilers = [];
    private static array $directives = [];

    public static function registerCompiler(string $name, callable $factory): void
    {
        self::$compilers[$name] = $factory;
    }

    public static function registerDirective(string $name, callable $handler): void
    {
        self::$directives[$name] = $handler;
    }

    public static function getCompilers(): array
    {
        return self::$compilers;
    }

    public static function getDirectives(): array
    {
        return self::$directives;
    }
}