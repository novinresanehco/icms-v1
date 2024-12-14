<?php

namespace App\Core\CMS;

class ContentValidator
{
    private $security;
    private $monitor;

    public function validateContentData(array $data): void
    {
        try {
            // Required fields
            if (!$this->checkRequiredFields($data)) {
                throw new ValidationException('Missing required fields');
            }

            // Data format
            if (!$this->validateFormat($data)) {
                throw new ValidationException('Invalid data format');
            }

            // Security scan
            if (!$this->security->scanContent($data)) {
                throw new SecurityException('Content failed security scan');
            }

        } catch (\Exception $e) {
            $this->monitor->validationFailed($e);
            throw $e;
        }
    }

    private function checkRequiredFields(array $data): bool
    {
        $required = ['title', 'content', 'type'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        return true;
    }

    private function validateFormat(array $data): bool
    {
        $formats = [
            'title' => '/^[\w\s-]{1,200}$/',
            'type' => '/^[a-z_]{1,50}$/',
            'status' => '/^(draft|published)$/'
        ];

        foreach ($formats as $field => $pattern) {
            if (isset($data[$field]) && !preg_match($pattern, $data[$field])) {
                return false;
            }
        }
        return true;
    }
}
