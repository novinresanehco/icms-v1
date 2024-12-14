<?php

namespace App\Core\Template\Config;

class TemplateConfig
{
    private array $config;
    private SecurityValidator $validator;

    private array $requiredConfigs = [
        'templates_path',
        'cache_enabled',
        'security_mode',
        'allowed_tags'
    ];

    private array $defaultConfig = [
        'cache_ttl' => 3600,
        'security_mode' => 'strict',
        'versioning' => true,
        'optimization' => true
    ];

    public function __construct(SecurityValidator $validator)
    {
        $this->validator = $validator;
        $this->config = array_merge(
            $this->defaultConfig,
            config('templates', [])
        );
        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        foreach ($this->requiredConfigs as $required) {
            if (!isset($this->config[$required])) {
                throw new ConfigurationException("Missing required config: $required");
            }
        }
        
        $this->validatePaths();
        $this->validateSecuritySettings();
    }

    private function validatePaths(): void
    {
        $templatesPath = $this->config['templates_path'];
        if (!is_dir($templatesPath) || !is_readable($templatesPath)) {
            throw new ConfigurationException("Invalid templates path: $templatesPath");
        }
    }

    private function validateSecuritySettings(): void
    {
        $this->validator->validateSecurityMode($this->config['security_mode']);
        $this->validator->validateAllowedTags($this->config['allowed_tags']);
    }
}

class TemplateRegistry
{
    private array $templates = [];
    private SecurityValidator $validator;
    private TemplateConfig $config;

    public function __construct(
        SecurityValidator $validator,
        TemplateConfig $config
    ) {
        $this->validator = $validator;
        $this->config = $config;
    }

    public function register(string $name, array $template): void
    {
        $this->validator->validateTemplate($name, $template);
        $this->templates[$name] = new Template($name, $template, $this->validator);
    }

    public function get(string $name): Template
    {
        if (!isset($this->templates[$name])) {
            throw new TemplateNotFoundException("Template not found: $name");
        }
        return $this->templates[$name];
    }
}

class TemplateInitializer
{
    private TemplateRegistry $registry;
    private TemplateConfig $config;
    private SecurityValidator $validator;

    public function __construct(
        TemplateRegistry $registry,
        TemplateConfig $config,
        SecurityValidator $validator
    ) {
        $this->registry = $registry;
        $this->config = $config;
        $this->validator = $validator;
    }

    public function initialize(): void
    {
        $this->loadSystemTemplates();
        $this->loadCustomTemplates();
        $this->validateTemplates();
    }

    private function loadSystemTemplates(): void
    {
        $systemTemplates = require $this->config->get('system_templates_path');
        foreach ($systemTemplates as $name => $template) {
            $this->registry->register($name, $template);
        }
    }

    private function loadCustomTemplates(): void
    {
        $customTemplatesPath = $this->config->get('templates_path');
        if (is_dir($customTemplatesPath)) {
            foreach (glob("$customTemplatesPath/*.php") as $file) {
                $template = require $file;
                $name = basename($file, '.php');
                $this->registry->register($name, $template);
            }
        }
    }

    private function validateTemplates(): void
    {
        foreach ($this->registry->all() as $template) {
            $this->validator->validateTemplateStructure($template);
        }
    }
}
