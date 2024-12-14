<?php

namespace App\Core\Media\Security;

class MediaSecurityService
{
    private $validator;
    private $monitor;
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];
    private const MAX_SIZE = 10485760; // 10MB

    public function validateFile(UploadedFile $file): void
    {
        try {
            // Check file type
            if (!in_array($file->getMimeType(), self::ALLOWED_TYPES)) {
                throw new SecurityException('Invalid file type');
            }

            // Check file size
            if ($file->getSize() > self::MAX_SIZE) {
                throw new SecurityException('File too large');
            }

            // Scan file content
            if (!$this->scanFileContent($file)) {
                throw new SecurityException('File content validation failed');
            }

        } catch (\Exception $e) {
            $this->monitor->securityCheckFailed($e);
            throw $e;
        }
    }

    private function scanFileContent(UploadedFile $file): bool
    {
        // Check for malware
        if (!$this->validator->scanForMalware($file)) {
            return false;
        }

        // Verify file integrity
        if (!$this->validator->verifyFileIntegrity($file)) {
            return false;
        }

        return true;
    }
}
