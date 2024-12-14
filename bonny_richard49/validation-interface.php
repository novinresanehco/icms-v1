<?php

namespace App\Core\Interfaces;

interface ValidationServiceInterface
{
    public function validateData(array $data, array $rules): array;
    public function validateContent(array $content): array;
    public function validateUser(array $userData): array;
    public function validatePermissions(array $permissions): array;
    public function calculatePasswordStrength(string $password): float;
    public function sanitizeInput(array $data, array $fields): array;
}
