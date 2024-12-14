<?php

namespace App\Core\Template\Security;

use Illuminate\Support\Collection;
use App\Core\Template\Exceptions\SecurityException;

class SecurityManager
{
    private Collection $sanitizers;
    private Collection $validators;
    private SecurityPolicy $policy;
    private SecurityLogger $logger;
    private array $config;

    public function __construct(
        SecurityPolicy $policy,
        SecurityLogger $logger,
        array $config = []
    ) {
        $this->sanitizers = new Collection();
        $this->validators = new Collection();
        $this->policy = $policy;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->registerDefaultSanitizers();
    }

    /**
     * Sanitize template content
     *
     * @param string $content
     * @param array $context
     * @return string
     */
    public function sanitize(string $content, array $context = []): string
    {
        foreach ($this->sanitizers as $sanitizer) {
            try {
                $content = $sanitizer->sanitize($content, $context);
            } catch (\Exception $e) {
                $this->logger->logError('sanitization_failed', $e, [
                    'sanitizer' => get_class($sanitizer),
                    'content_length' => strlen($content)
                ]);
                throw new SecurityException("Sanitization failed: {$e->getMessage()}", 0, $e);
            }
        }

        return $content;
    }

    /**
     * Validate template content
     *
     * @param string $content
     * @param array $context
     * @return bool
     */
    public function validate(string $content, array $context = []): bool
    {
        foreach ($this->validators as $validator) {
            if (!$validator->validate($content, $context)) {
                $this->logger->logViolation('validation_failed', [
                    'validator' => get_class($validator),
                    'content_length' => strlen($content)
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Check if action is allowed
     *
     * @param string $action
     * @param array $context
     * @return bool
     */
    public function isAllowed(string $action, array $context = []): bool
    {
        return $this->policy->isAllowed($action, $context);
    }

    /**
     * Register sanitizer
     *
     * @param ContentSanitizer $sanitizer
     * @return void
     */
    public function registerSanitizer(ContentSanitizer $sanitizer): void
    {
        $this->sanitizers->push($sanitizer);
    }

    /**
     * Register validator
     *
     * @param ContentValidator $validator
     * @return void
     */
    public function registerValidator(ContentValidator $validator): void
    {
        $this->validators->push($validator);
    }

    /**
     * Register default sanitizers
     *
     * @return void
     */
    protected function registerDefaultSanitizers(): void
    {
        $this->registerSanitizer(new XSSProtectionSanitizer());
        $this->registerSanitizer(new HTMLPurifierSanitizer());
        $this->registerSanitizer(new ScriptSanitizer());
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enable_xss_protection' => true,
            'allow_html' => true,
            'allowed_tags' => ['p', 'a', 'b', 'i', 'strong', 'em'],
            'allowed_attributes' => ['href', 'title', 'class'],
            'max_content_length' => 50000
        ];
    }
}

abstract class ContentSanitizer
{
    /**
     * Sanitize content
     *
     * @param string $content
     * @param array $context
     * @return string
     */
    abstract public function sanitize(string $content, array $context = []): string;
}

class XSSProtectionSanitizer extends ContentSanitizer
{
    /**
     * Sanitize content against XSS
     *
     * @param string $content
     * @param array $context
     * @return string
     */
    public function sanitize(string $content, array $context = []): string
    {
        // Basic XSS prevention
        $content = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove potentially dangerous attributes
        $content = preg_replace(
            '/javascript:[^\"]*/i',
            '',
            $content
        );

        return $content;
    }
}

class HTMLPurifierSanitizer extends ContentSanitizer
{
    private \HTMLPurifier $purifier;

    public function __construct()
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,a[href],b,i,strong,em');
        $this->purifier = new \HTMLPurifier($config);
    }

    /**
     * Sanitize content using HTML Purifier
     *
     * @param string $content
     * @param array $context
     * @return string
     */
    public function sanitize(string $content, array $context = []): string
    {
        return $this->purifier->purify($content);
    }
}

class ScriptSanitizer extends ContentSanitizer
{
    /**
     * Sanitize script tags and inline JavaScript
     *
     * @param string $content
     * @param array $context
     * @return string
     */
    public function sanitize(string $content, array $context = []): string
    {
        // Remove script tags
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        
        // Remove on* attributes
        $content = preg_replace('/\bon\w+="[^"]*"/i', '', $content);
        
        return $content;
    }
}

class SecurityPolicy
{
    private array $rules;

    /**
     * Check if action is allowed
     *
     * @param string $action
     * @param array $context
     * @return bool
     */
    public function isAllowed(string $action, array $context = []): bool
    {
        if (!isset($this->rules[$action])) {
            return false;
        }

        foreach ($this->rules[$action] as $rule) {
            if (!$this->evaluateRule($rule, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add security rule
     *
     * @param string $action
     * @param callable $rule
     * @return void
     */
    public function addRule(string $action, callable $rule): void
    {
        if (!isset($this->rules[$action])) {
            $this->rules[$action] = [];
        }

        $this->rules[$action][] = $rule;
    }

    /**
     * Evaluate security rule
     *
     * @param callable $rule
     * @param array $context
     * @return bool
     */
    protected function evaluateRule(callable $rule, array $context): bool
    {
        try {
            return call_user_func($rule, $context);
        } catch (\Exception $e) {
            return false;
        }
    }
}

class SecurityLogger
{
    /**
     * Log security event
     *
     * @param string $event
     * @param array $context
     * @return void
     */
    public function logEvent(string $event, array $context = []): void
    {
        logger()->info("Security event: {$event}", $context);
    }

    /**
     * Log security violation
     *
     * @param string $type
     * @param array $context
     * @return void
     */
    public function logViolation(string $type, array $context = []): void
    {
        logger()->warning("Security violation: {$type}", $context);
    }

    /**
     * Log security error
     *
     * @param string $type
     * @param \Exception $e
     * @param array $context
     * @return void
     */
    public function logError(string $type, \Exception $e, array $context = []): void
    {
        logger()->error("Security error: {$type}", array_merge([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], $context));
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Security\SecurityManager;
use App\Core\Template\Security\SecurityPolicy;
use App\Core\Template\Security\SecurityLogger;

class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class, function ($app) {
            $policy = new SecurityPolicy();
            
            // Register default security rules
            $this->registerSecurityRules($policy);
            
            return new SecurityManager(
                $policy,
                new SecurityLogger(),
                config('template.security')
            );
        });
    }

    /**
     * Register default security rules
     *
     * @param SecurityPolicy $policy
     * @return void
     */
    protected function registerSecurityRules(SecurityPolicy $policy): void
    {
        // Template editing
        $policy->addRule('edit_template', function ($context) {
            return auth()->user()?->can('edit_templates');
        });

        // Template deletion
        $policy->addRule('delete_template', function ($context) {
            return auth()->user()?->can('delete_templates');
        });

        // Template creation
        $policy->addRule('create_template', function ($context) {
            return auth()->user()?->can('create_templates');
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Register middleware for template security
        $this->app['router']->pushMiddleware(\App\Http\Middleware\TemplateSecurityMiddleware::class);
    }
}
