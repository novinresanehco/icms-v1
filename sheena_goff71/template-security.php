namespace App\Core\Template\Security;

class TemplateSecurityManager implements SecurityInterface
{
    private ValidationService $validator;
    private array $securityRules;
    private array $sanitizationRules;

    public function validateTemplate(string $template): bool
    {
        try {
            $this->scanForMaliciousCode($template);
            $this->validateSyntax($template);
            $this->checkSecurityConstraints($template);
            return true;
        } catch (SecurityException $e) {
            $this->logSecurityViolation($e);
            return false;
        }
    }

    public function sanitizeOutput(string $output): string
    {
        $sanitized = $this->escapeHtml($output);
        $sanitized = $this->sanitizeScripts($sanitized);
        $sanitized = $this->sanitizeUrls($sanitized);
        return $this->finalSanitizationCheck($sanitized);
    }

    public function validateDirective(string $directive, array $params): bool
    {
        if (!isset($this->securityRules[$directive])) {
            throw new SecurityException("Unauthorized template directive: $directive");
        }
        return $this->validator->validateDirectiveParams($directive, $params);
    }

    private function scanForMaliciousCode(string $template): void
    {
        $patterns = [
            '/\b(eval|exec|system|shell_exec|passthru)\b/i',
            '/\b(file_get_contents|file_put_contents|fopen|unlink)\b/i',
            '/<\?(?!xml)/i',
            '/\bwindow\b|\bdocument\b|\balert\b/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $template)) {
                throw new SecurityException('Malicious code detected in template');
            }
        }
    }

    private function validateSyntax(string $template): void
    {
        $tokens = token_get_all($template);
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $this->validateToken($token);
            }
        }
    }

    private function validateToken(array $token): void
    {
        $forbiddenTokens = [
            T_EVAL,
            T_INCLUDE,
            T_INCLUDE_ONCE,
            T_REQUIRE,
            T_REQUIRE_ONCE
        ];

        if (in_array($token[0], $forbiddenTokens)) {
            throw new SecurityException('Forbidden PHP token detected');
        }
    }

    private function checkSecurityConstraints(string $template): void
    {
        // Validate template size
        if (strlen($template) > config('template.max_size')) {
            throw new SecurityException('Template exceeds maximum allowed size');
        }

        // Check nesting level
        if ($this->calculateNestingLevel($template) > config('template.max_nesting')) {
            throw new SecurityException('Template exceeds maximum nesting level');
        }
    }

    private function calculateNestingLevel(string $template): int
    {
        $level = 0;
        $maxLevel = 0;
        $tokens = token_get_all($template);

        foreach ($tokens as $token) {
            if ($token === '{') {
                $level++;
                $maxLevel = max($maxLevel, $level);
            } elseif ($token === '}') {
                $level--;
            }
        }

        return $maxLevel;
    }

    private function escapeHtml(string $output): string
    {
        return htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function sanitizeScripts(string $output): string
    {
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $output);
    }

    private function sanitizeUrls(string $output): string
    {
        return preg_replace_callback('/href=["\'](.*?)["\']/i', function($matches) {
            return 'href="' . $this->validateUrl($matches[1]) . '"';
        }, $output);
    }

    private function validateUrl(string $url): string
    {
        $allowedProtocols = ['http', 'https', 'mailto', 'tel'];
        $protocol = parse_url($url, PHP_URL_SCHEME);

        if ($protocol && !in_array($protocol, $allowedProtocols)) {
            throw new SecurityException("Invalid URL protocol: $protocol");
        }

        return filter_var($url, FILTER_SANITIZE_URL);
    }

    private function finalSanitizationCheck(string $output): string
    {
        foreach ($this->sanitizationRules as $rule => $callback) {
            $output = $callback($output);
        }
        return $output;
    }

    private function logSecurityViolation(\Exception $e): void
    {
        Log::critical('Template security violation', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'time' => now()
        ]);
    }
}
