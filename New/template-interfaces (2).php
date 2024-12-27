<?php

namespace App\Core\Template\Interfaces;

interface TemplateEngineInterface
{
    public function render(string $template, array $data = []): string;
    public function extend(string $template, array $blocks): Template;
}

interface ThemeManagerInterface
{
    public function register(string $name, Theme $theme): void;
    public function activate(string $name): void;
    public function getActive(): Theme;
}

interface AssetManagerInterface
{
    public function publishThemeAssets(Theme $theme): void;
}

interface BlockManagerInterface
{
    public function register(string $name, callable $renderer): void;
    public function render(string $name, array $data = []): string;
}

class CompiledTemplate
{
    private string $path;
    private int $timestamp;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->timestamp = filemtime($path);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}

class Template
{
    private string $parent;
    private array $blocks;

    public function __construct(string $parent, array $blocks)
    {
        $this->parent = $parent;
        $this->blocks = $blocks;
    }

    public function getParent(): string
    {
        return $this->parent;
    }

    public function getBlocks(): array
    {
        return $this->blocks;
    }
}
