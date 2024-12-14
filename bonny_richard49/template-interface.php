<?php

namespace App\Core\Interfaces;

interface TemplateServiceInterface
{
    public function render(string $template, array $data = [], array $options = []): string;
    public function compile(string $template): string;
    public function extends(string $name, callable $extension): void;
    public function registerFunction(string $name, callable $function): void;
}
