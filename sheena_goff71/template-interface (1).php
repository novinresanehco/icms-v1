<?php

namespace App\Core\Template;

interface TemplateServiceInterface
{
    /**
     * Renders a template with security controls and caching
     * 
     * @param string $template Template identifier
     * @param array $data Template data
     * @param array $options Rendering options
     * @return string Rendered content
     * @throws TemplateException If rendering fails
     */
    public function render(string $template, array $data = [], array $options = []): string;

    /**
     * Compiles a template for caching
     * 
     * @param string $template Template content
     * @return string Compiled template
     * @throws TemplateException If compilation fails
     */
    public function compile(string $template): string;

    /**
     * Validates a template structure
     * 
     * @param string $template Template to validate
     * @return bool Validation result
     */
    public function validate(string $template): bool;

    /**
     * Extends the template engine with custom functionality
     * 
     * @param string $name Extension name
     * @param callable $extension Extension callback
     * @return void
     */
    public function extend(string $name, callable $extension): void;
}
