<?php

namespace App\Core\Validation;

class TemplateValidator implements ValidatorInterface
{
    private SecurityConfig $config;
    private ContentScanner $scanner;

    public function validateTemplate(Template $template): ValidationResult
    {
        return new ValidationResult([
            $this->validateStructure($template),
            $this->validateContent($template),
            $this->validateSecurity($template)
        ]);
    }

    private function validateStructure(Template $template): bool
    {
        return $template->hasValidSyntax() && 
               $template->hasRequiredBlocks() &&
               $template->isCorrectlyNested();
    }

    private function validateContent(Template $template): bool
    {
        return $this->scanner->checkForMaliciousCode($template->content) &&
               $this->scanner->validateResourceRefs($template->content);
    }

    private function validateSecurity(Template $template): bool
    {
        return $this->scanner->checkSecurityHeaders($template) &&
               $this->scanner->validateCSP($template) &&
               $this->scanner->checkXSS($template);
    }
}

class MediaValidator implements ValidatorInterface
{
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    private int $maxFileSize = 5242880; // 5MB

    public function validateMedia(UploadedFile $file): ValidationResult
    {
        return new ValidationResult([
            $this->validateFileType($file),
            $this->validateFileSize($file),
            $this->validateDimensions($file),
            $this->scanForMalware($file)
        ]);
    }

    private function validateFileType(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), $this->allowedTypes);
    }

    private function validateFileSize(UploadedFile $file): bool
    {
        return $file->getSize() <= $this->maxFileSize;
    }

    private function validateDimensions(UploadedFile $file): bool
    {
        $image = getimagesize($file->path());
        return $image[0] <= 4096 && $image[1] <= 4096;
    }

    private function scanForMalware(UploadedFile $file): bool
    {
        // Implement malware scanning
        return true;
    }
}
