<?php

namespace App\Core\Template\Interfaces;

interface TemplateEngineInterface
{
    public function render(string $template, array $data): string;
    public function compile(string $template): CompiledTemplate;
    public function validate(string $template): ValidationResult;
}

interface ContentRendererInterface
{
    public function renderContent(Content $content, Template $template): string;
    public function validateContent(Content $content): ValidationResult;
    public function getCacheKey(Content $content, Template $template): string;
}

interface MediaGalleryInterface
{
    public function renderGallery(array $media, GalleryConfig $config): string;
    public function validateMedia(array $media): ValidationResult;
    public function processMedia(MediaItem $item, GalleryConfig $config): ProcessedMedia;
}

interface UIComponentInterface
{
    public function renderComponent(string $name, array $props): string;
    public function validateComponent(string $name): ValidationResult;
    public function validateProps(array $props, PropSchema $schema): ValidationResult;
}

interface RenderContextInterface
{
    public function getData(): array;
    public function getProtectedBlocks(): array;
    public function getSanitizer(): HtmlSanitizerInterface;
    public function getValidator(): ValidationServiceInterface;
}

interface CompiledTemplateInterface
{
    public function render(RenderContext $context): string;
    public function getCacheKey(): string;
    public function getRequiredPermissions(): array;
}
