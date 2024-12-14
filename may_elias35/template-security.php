```php
namespace App\Core\Template\Security;

final class TemplateSecurityManager
{
    private XSSProtection $xssProtector;
    private CSRFProtection $csrfProtector;
    private ValidationService $validator;

    public function validateTemplate(string $template): bool
    {
        return $this->validator->validateSyntax($template) &&
               $this->xssProtector->scan($template) &&
               $this->csrfProtector->verify($template);
    }

    public function sanitizeOutput(string $output): string
    {
        return $this->xssProtector->clean(
            $this->validator->sanitize($output)
        );
    }

    public function verifyRenderContext(RenderContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid render context');
        }
    }
}

final class XSSProtection
{
    private array $dangerousPatterns = [
        '/<script\b[^>]*>(.*?)<\/script>/is',
        '/on\w+\s*=\s*(?:\'|").+?(?:\'|")/is',
        '/javascript:\s*(?:\'|").+?(?:\'|")/is'
    ];

    public function scan(string $content): bool
    {
        foreach ($this->dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        return true;
    }

    public function clean(string $content): string
    {
        return htmlspecialchars($content, ENT_QUOTES | ENT_HTML5);
    }
}
```
