<?php

namespace App\Core\Template\Validation;

class TemplateValidator implements ValidationInterface
{
    private SecurityManager $security;
    private ContentValidator $contentValidator;
    private SchemaValidator $schemaValidator;

    public function validateTemplate(Template $template): ValidationResult 
    {
        // Validate structure
        $this->validateStructure($template);
        
        // Validate content
        $this->validateContent($template);
        
        // Validate security constraints
        $this->validateSecurity($template);
        
        return new ValidationResult(true);
    }

    private function validateStructure(Template $template): void
    {
        if (!$this->schemaValidator->validate($template->getSchema())) {
            throw new ValidationException('Invalid template structure');
        }
    }

    private function validateContent(Template $template): void
    {
        foreach ($template->getBlocks() as $block) {
            if (!$this->contentValidator->validate($block)) {
                throw new ValidationException("Invalid content block: {$block->id}");
            }
        }
    }

    private function validateSecurity(Template $template): void
    {
        if (!$this->security->validateTemplate($template)) {
            throw new SecurityException('Template security validation failed');
        }
    }
}

class ContentValidator implements ContentValidationInterface
{
    private SecurityManager $security;
    private MediaValidator $mediaValidator;
    private LinkValidator $linkValidator;
    private ScriptValidator $scriptValidator;

    public function validateContent(Content $content): bool
    {
        try {
            // Validate basic structure
            $this->validateStructure($content);
            
            // Validate media elements
            $this->validateMediaElements($content);
            
            // Validate links
            $this->validateLinks($content);
            
            // Validate scripts
            $this->validateScripts($content);
            
            // Validate security constraints
            $this->validateSecurity($content);
            
            return true;
            
        } catch (\Exception $e) {
            throw new ValidationException(
                "Content validation failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    private function validateStructure(Content $content): void
    {
        $required = ['title', 'body', 'type'];
        foreach ($required as $field) {
            if (empty($content->$field)) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    private function validateMediaElements(Content $content): void
    {
        foreach ($content->getMediaElements() as $media) {
            $this->mediaValidator->validate($media);
        }
    }

    private function validateLinks(Content $content): void
    {
        foreach ($content->getLinks() as $link) {
            $this->linkValidator->validate($link);
        }
    }

    private function validateScripts(Content $content): void
    {
        foreach ($content->getScripts() as $script) {
            $this->scriptValidator->validate($script);
        }
    }
}