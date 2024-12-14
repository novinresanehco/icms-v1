<?php

namespace App\Core\UI;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\SecurityException;

class UISecurityService implements UISecurityInterface
{
    private SecurityManagerInterface $security;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        array $config = []
    ) {
        $this->security = $security;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateComponentAccess(string $componentName, array $context): bool
    {
        try {
            // Check basic permissions
            if (!$this->security->hasPermission('ui:access')) {
                throw new SecurityException('No UI access permission');
            }

            // Check component-specific permissions
            $requiredPermission = "ui:component:{$componentName}";
            if (!$this->security->hasPermission($requiredPermission)) {
                throw new SecurityException("No access to component: {$componentName}");
            }

            // Validate context
            $this->validateSecurityContext($context);

            return true;

        } catch (SecurityException $e) {
            // Log security violation
            $this->security->logSecurityEvent('ui_access_denied', [
                'component' => $componentName,
                'user_id' => $context['user_id'] ?? null,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function sanitizeComponentOutput(string $output): string
    {
        // Remove dangerous content
        $output = preg_replace(
            $this->config['dangerous_patterns'],
            '',
            $output
        );

        // Encode special characters
        $output = htmlspecialchars($output, ENT_QUOTES | ENT_HTML5);

        // Add security headers
        $output = $this->addSecurityHeaders($output);

        return $output;
    }

    public function validateComponentProps(array $props, array $schema): void
    {
        foreach ($props as $key => $value) {
            if (!isset($schema[$key])) {
                throw new SecurityException("Unauthorized prop: {$key}");
            }

            $this->validatePropValue($key, $value, $schema[$key]);
        }
    }

    private function validateSecurityContext(array $context): void
    {
        $required = ['user_id', 'session_id', 'ip_address'];
        foreach ($required as $field) {
            if (!isset($context[$field])) {
                throw new SecurityException("Missing required security context: {$field}");
            }
        }

        // Validate user session
        if (!$this->security->validateSession($context['session_id'])) {
            throw new SecurityException('Invalid session');
        }

        // Validate IP address
        if (!$this->security->validateIpAddress($context['ip_address'])) {
            throw new SecurityException('Invalid IP address');
        }
    }

    private function validatePropValue(string $key, $value, array $rules): void
    {
        // Type validation
        if (isset($rules['type']) && gettype($value) !== $rules['type']) {
            throw new SecurityException("Invalid type for prop {$key}");
        }

        // Pattern validation
        if (isset($rules['pattern']) && is_string($value)) {
            if (!preg_match($rules['pattern'], $value)) {
                throw new SecurityException("Invalid pattern for prop {$key}");
            }
        }

        // Custom validation
        if (isset($rules['validate']) && is_callable($rules['validate'])) {
            if (!$rules['validate']($value)) {
                throw new SecurityException("Validation failed for prop {$key}");
            }
        }
    }

    private function addSecurityHeaders(string $output): string
    {
        $headers = [
            'Content-Security-Policy' => $this->config['csp_policy'],
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff'
        ];

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        return $output;
    }

    private function getDefaultConfig(): array
    {
        return [
            'dangerous_patterns' => [
                '/<script\b[^>]*>(.*?)<\/script>/is',
                '/on\w+="[^"]*"/is',
                '/javascript:/i'
            ],
            'csp_policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'",
            'max_props_depth' => 3,
            'max_output_size' => 1000000 // 1MB
        ];
    }
}
