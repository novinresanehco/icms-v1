<?php

namespace App\Core\Validation;

use App\Core\Exceptions\TemplateValidationException;
use Illuminate\Support\Facades\Blade;

class TemplateValidator
{
    protected array $allowedTypes = ['page', 'partial', 'layout', 'email', 'component'];

    public function validateCreation(array $data): void
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Template name is required';
        }

        if (empty($data['content'])) {
            $errors[] = 'Template content is required';
        }

        if (!empty($data['type']) && !in_array($data['type'], $this->allowedTypes)) {
            $errors[] = 'Invalid template type';
        }

        if (!empty($errors)) {
            throw new TemplateValidationException(implode(', ', $errors));
        }
    }

    public function validateUpdate(array $data): void
    {
        $errors = [];

        if (isset($data['type']) && !in_array($data['type'], $this->allowedTypes)) {
            $errors[] = 'Invalid template type';
        }

        if (!empty($data['content'])) {
            try {
                $this->validateSyntax($data['content']);
            } catch (TemplateValidationException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new TemplateValidationException(implode(', ', $errors));
        }
    }

    public function validateSyntax(string $content): void
    {
        try {
            Blade::compileString($content);
        } catch (\Exception $e) {
            throw new TemplateValidationException("Invalid template syntax: {$e->getMessage()}");
        }
    }

    public function validateImport(array $data): void
    {
        $errors = [];

        $requiredFields = ['name', 'content', 'type'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing