<?php

namespace App\Core\Template\Inheritance;

class TemplateInheritanceManager
{
    private SecurityManager $security;
    private ValidatorInterface $validator;
    private array $templates = [];

    public function __construct(
        SecurityManager $security,
        ValidatorInterface $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function extend(string $parent, string $child): void
    {
        DB::transaction(function() use ($parent, $child) {
            $this->security->validateTemplateExtension($parent, $child);
            $this->validator->validateInheritance($parent, $child);
            
            $this->templates[$child] = [
                'parent' => $parent,
                'blocks' => [],
                'overrides' => []
            ];
        });
    }

    public function defineBlock(string $template, string $block, callable $content): void
    {
        $this->validator->validateBlock($template, $block);
        $this->templates[$template]['blocks'][$block] = $content;
    }

    public function override(string $template, string $block, callable $content): void
    {
        $this->validator->validateOverride($template, $block);
        $this->templates[$template]['overrides'][$block] = $content;
    }

    public function render(string $template): string
    {
        return DB::transaction(function() use ($template) {
            $this->security->validateTemplateRender($template);
            return $this->renderTemplate($template);
        });
    }

    private function renderTemplate(string $template): string
    {
        $inheritance = $this->resolveInheritance($template);
        return $this->processInheritance($inheritance);
    }

    private function resolveInheritance(string $template): array
    {
        $chain = [];
        $current = $template;

        while (isset($this->templates[$current])) {
            $chain[] = $current;
            $current = $this->templates[$current]['parent'] ?? null;
            
            if (in_array($current, $chain)) {
                throw new CircularInheritanceException();
            }
        }

        return array_reverse($chain);
    }

    private function processInheritance(array $chain): string
    {
        $blocks = [];
        
        foreach ($chain as $template) {
            $templateData = $this->templates[$template];
            
            foreach ($templateData['blocks'] as $name => $content) {
                if (!isset($blocks[$name])) {
                    $blocks[$name] = $content;
                }
            }
            
            foreach ($templateData['overrides'] as $name => $content) {
                $blocks[$name] = $content;
            }
        }

        return $this->renderBlocks($blocks);
    }

    private function renderBlocks(array $blocks): string
    {
        $output = '';
        foreach ($blocks as $name => $content) {
            $output .= $this->security->sanitizeOutput(
                $content()
            );
        }
        return $output;
    }
}

class CircularInheritanceException extends \Exception
{
    public function __construct()
    {
        parent::__construct('Circular template inheritance detected');
    }
}

interface InheritanceInterface
{
    public function extend(string $parent, string $child): void;
    public function defineBlock(string $template, string $block, callable $content): void;
    public function override(string $template, string $block, callable $content): void;
    public function render(string $template): string;
}
