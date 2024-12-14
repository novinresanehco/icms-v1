<?php

namespace App\Core\Template\Security;

class TemplateSecurityManager 
{
    private ValidatorInterface $validator;
    private AuditLogger $logger;
    private array $securityRules;

    public function __construct(
        ValidatorInterface $validator,
        AuditLogger $logger,
        array $securityRules
    ) {
        $this->validator = $validator;
        $this->logger = $logger;
        $this->securityRules = $securityRules;
    }

    public function validateTemplateOperation(string $operation, array $context): void 
    {
        DB::transaction(function() use ($operation, $context) {
            $this->validator->validateOperation($operation);
            $this->enforceSecurityBoundaries($operation, $context);
            $this->logger->logOperation($operation, $context);
        });
    }

    private function enforceSecurityBoundaries(string $operation, array $context): void 
    {
        $rules = $this->securityRules[$operation] ?? [];
        
        foreach ($rules as $rule) {
            if (!$this->validateRule($rule, $context)) {
                throw new SecurityViolationException($rule);
            }
        }
    }

    private function validateRule(string $rule, array $context): bool 
    {
        return match($rule) {
            'template_boundary' => $this->validateTemplateBoundary($context),
            'component_access' => $this->validateComponentAccess($context),
            'media_security' => $this->validateMediaSecurity($context),
            'rendering_safety' => $this->validateRenderingSafety($context),
            default => false
        };
    }

    private function validateTemplateBoundary(array $context): bool 
    {
        return $context['type'] === 'template' && 
               isset($context['path']) && 
               str_starts_with($context['path'], 'templates/');
    }

    private function validateComponentAccess(array $context): bool 
    {
        return $context['type'] === 'component' && 
               isset($context['namespace']) && 
               in_array($context['namespace'], ['ui', 'content', 'media']);
    }

    private function validateMediaSecurity(array $context): bool 
    {
        return $context['type'] === 'media' && 
               isset($context['source']) && 
               filter_var($context['source'], FILTER_VALIDATE_URL) && 
               parse_url($context['source'], PHP_URL_SCHEME) === 'https';
    }

    private function validateRenderingSafety(array $context): bool 
    {
        return $context['type'] === 'render' && 
               isset($context['sanitized']) && 
               $context['sanitized'] === true;
    }
}

class SecurityViolationException extends \Exception 
{
    public function __construct(string $rule) 
    {
        parent::__construct("Security violation: {$rule}");
    }
}

interface SecurityInterface 
{
    public function validateTemplateOperation(string $operation, array $context): void;
}
