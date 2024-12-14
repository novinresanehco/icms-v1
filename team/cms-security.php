<?php

namespace App\Core\CMS\Security;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Models\Content;
use App\Core\Exceptions\SecurityException;

class ContentSecurityService
{
    private SecurityManager $security;
    private ValidationService $validator;

    /**
     * Validates content security requirements
     */
    public function validateContentSecurity(Content $content): void
    {
        $this->security->executeCritical('content:security:validate', function() use ($content) {
            // Validate content structure
            if (!$this->validator->validateStructure($content)) {
                throw new SecurityException('Content structure validation failed');
            }

            // Check content security requirements
            if (!$this->validator->checkSecurityRequirements($content)) {
                throw new SecurityException('Content security requirements not met');
            }

            // Validate media security if present
            if ($content->hasMedia()) {
                $this->validateMediaSecurity($content->media);
            }

            // Verify content integrity
            if (!$this->verifyContentIntegrity($content)) {
                throw new SecurityException('Content integrity check failed');
            }
        });
    }

    /**
     * Implements content access control
     */
    public function enforceContentAccess(Content $content, string $operation): void
    {
        $this->security->executeCritical('content:security:access', function() use ($content, $operation) {
            // Verify user permissions
            if (!$this->security->hasPermission("content:{$operation}")) {
                throw new SecurityException("Insufficient permissions for {$operation}");
            }

            // Check content specific restrictions
            if (!$this->canAccessContent($content, $operation)) {
                throw new SecurityException('Content access denied');
            }

            // Log access attempt
            $this->security->logAccess('content', $content->id, $operation);
        });
    }

    /**
     * Validates media security requirements
     */
    private function validateMediaSecurity(array $media): void
    {
        foreach ($media as $item) {
            // Validate media type
            if (!$this->validator->isAllowedMediaType($item)) {
                throw new SecurityException('Invalid media type detected');
            }

            // Scan media content
            if (!$this->security->scanMedia($item)) {
                throw new SecurityException('Media security scan failed');
            }

            // Verify media integrity
            if (!$this->verifyMediaIntegrity($item)) {
                throw new SecurityException('Media integrity check failed');
            }
        }
    }

    /**
     * Verifies content integrity
     */
    private function verifyContentIntegrity(Content $content): bool
    {
        return $this->security->verifyIntegrity([
            'content' => $content->toArray(),
            'hash' => $content->integrity_hash
        ]);
    }

    /**
     * Checks content-specific access rules
     */
    private function canAccessContent(Content $content, string $operation): bool
    {
        return $this->security->evaluateAccess([
            'content' => $content,
            'operation' => $operation,
            'user' => auth()->user(),
            'context' => request()->all()
        ]);
    }
}
