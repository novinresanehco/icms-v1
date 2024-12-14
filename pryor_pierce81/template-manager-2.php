<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\TemplateException;
use Psr\Log\LoggerInterface;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $engines = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function render(string $template, array $data = []): string 
    {
        $renderingId = $this->generateRenderingId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('template:render', [
                'template' => $template
            ]);

            $this->validateTemplate($template);
            $this->validateTemplateData($data);

            $engine = $this->getTemplateEngine($template);
            $rendered = $this->processTemplate($engine, $template, $data);

            $this->validateRenderedOutput($rendered);
            $this->logTemplateRendering($renderingId, $template);

            DB::commit();
            return $rendered;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRenderingFailure($renderingId, $template, $e);
            throw new TemplateException('Template rendering failed', 0, $e);
        }
    }

    public function compile(string $template): CompiledTemplate 
    {
        $compilationId = $this->generateCompilationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('template:compile', [
                'template' => $template
            ]);

            $this->validateTemplate($template);
            
            $engine = $this->getTemplateEngine($template);
            $compiled = $this->compileTemplate($engine, $template);

            $this->validateCompiledTemplate($compiled);
            $this->logTemplateCompilation($compilationId, $template);

            DB::commit();
            return $compiled;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCompilationFailure($compilationId, $template, $e);
            throw new TemplateException('Template compilation failed', 0, $e);
        }
    }

    private function validateTemplate(string $template): void 
    {
        if (!$this->templateExists($template)) {
            throw new TemplateException('Template not found');
        }

        if (!$this->isTemplateSecure($template)) {
            throw new TemplateException('Template security validation failed');
        }

        foreach ($this->config['validation_rules'] as $rule) {
            if (!$this->validateTemplateRule($template, $rule)) {
                throw new TemplateException("Template validation failed: {$rule}");
            }
        }
    }

    private function validateTemplateData(array $data): void 
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidDataType($value)) {
                throw new TemplateException("Invalid data type for key: {$key}");
            }

            if (!$this->isSecureData($value)) {
                throw new TemplateException("Insecure data detected for key: {$key}");
            }
        }
    }

    private function processTemplate(TemplateEngine $engine, string $template, array $data): string 
    {
        $context = $this->createRenderingContext($template, $data);
        
        try {
            return $engine->render($template, $data, $context);
        } catch (\Exception $e) {
            $this->logger->error('Template processing failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function handleRenderingFailure(string $id, string $template, \Exception $e): void 
    {
        $this->logger->error('Template rendering failed', [
            'rendering_id' => $id,
            'template' => $template,
            'error' => $e->getMessage()
        ]);

        $this->notifyRenderingFailure($id, $template, $e);
    }

    private function getDefaultConfig(): array 
    {
        return [
            'template_paths' => [
                'theme' => resource_path('views/theme'),
                'admin' => resource_path('views/admin'),
                'email' => resource_path('views/email')
            ],
            'cache_enabled' => true,
            'cache_duration' => 3600,
            'strict_variables' => true,
            'auto_escape' => true,
            'validation_rules' => [
                'syntax',
                'security',
                'dependencies'
            ]
        ];
    }
}
