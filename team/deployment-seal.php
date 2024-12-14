<?php

namespace App\Core\Security;

class DeploymentSeal
{
    private const DEPLOYMENT_HASH = '1cfe26126eaa4784b84faef5fbe186a2f39e40b1';
    private const CRITICAL_COMPONENTS = [
        'security' => 'SecurityManager',
        'auth' => 'AuthenticationManager',
        'cms' => 'ContentManager',
        'template' => 'TemplateManager',
        'infrastructure' => 'InfrastructureManager'
    ];

    private function validateComponent(string $component, string $class): bool
    {
        $hash = sha1_file(app_path("Core/{$component}/{$class}.php"));
        return hash_equals($hash, self::DEPLOYMENT_HASH);
    }

    public function verify(): bool
    {
        foreach (self::CRITICAL_COMPONENTS as $path => $class) {
            if (!$this->validateComponent($path, $class)) {
                return false;
            }
        }
        return true;
    }
}
