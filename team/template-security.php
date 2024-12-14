<?php

namespace App\Core\Template\Security;

class SecurityManager implements SecurityManagerInterface 
{
    private EncryptionService $encryption;
    private TokenManager $tokens;
    private ValidationService $validator;

    public function validateTemplate(string $template): void 
    {
        if (!$this->validator->validateSyntax($template)) {
            throw new SecurityException('Invalid template syntax');
        }

        if ($this->validator->detectUnsafePattern($template)) {
            throw new SecurityException('Unsafe template pattern detected');
        }
    }

    public function createRenderContext(): RenderContext 
    {
        return new RenderContext([
            'timestamp' => time(),
            'token' => $this->tokens->generate(),
            'restrictions' => $this->getSecurityRestrictions()
        ]);
    }

    public function renderProtected(
        string $template, 
        array $data, 
        RenderContext $context
    ): string {
        $sandbox = $this->createSandbox($context);
        return $sandbox->render($template, $data);
    }

    public function validateContent(Content $content): void 
    {
        if (!$this->validator->validateContentSecurity($content)) {
            throw new SecurityException('Content security validation failed');
        }
    }

    public function sanitizeContent(Content $content): array 
    {
        return [
            'body' => $this->sanitizeHtml($content->body),
            'title' => $this->sanitizeText($content->title),
            'meta' => $this->sanitizeMeta($content->meta)
        ];
    }

    public function generateMediaToken(Media $media): string 
    {
        return $this->tokens->generateSigned([
            'media_id' => $media->id,
            'path' => $media->path,
            'expires' => time() + 3600
        ]);
    }

    private function createSandbox(RenderContext $context): TemplateSandbox 
    {
        return new TemplateSandbox(
            $this->validator,
            $context,
            $this->getSecurityPolicy()
        );
    }

    private function sanitizeHtml(string $html): string 
    {
        return $this->validator->sanitizeHtml($html, [
            'allowed_tags' => $this->getAllowedHtmlTags(),
            'allowed_attributes' => $this->getAllowedAttributes()
        ]);
    }

    private function sanitizeText(string $text): string 
    {
        return $this->validator->sanitizeText($text);
    }

    private function getSecurityRestrictions(): array 
    {
        return [
            'allowed_functions' => ['escape', 'translate'],
            'max_iterations' => 100,
            'max_execution_time' => 1,
            'memory_limit' => '128M'
        ];
    }

    private function getSecurityPolicy(): SecurityPolicy 
    {
        return new SecurityPolicy([
            'disable_functions' => ['exec', 'shell_exec', 'system'],
            'disable_classes' => ['ReflectionClass'],
            'restrict_filesystem' => true,
            'restrict_network' => true
        ]);
    }
}

interface SecurityManagerInterface 
{
    public function validateTemplate(string $template): void;
    public function createRenderContext(): RenderContext;
    public function renderProtected(string $template, array $data, RenderContext $context): string;
    public function validateContent(Content $content): void;
    public function sanitizeContent(Content $content): array;
    public function generateMediaToken(Media $media): string;
}
