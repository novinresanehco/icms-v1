<?php

namespace App\Core\Template\Security;

class SecurityValidator
{
    private array $allowedTags = [
        'div', 'span', 'p', 'h1', 'h2', 'h3', 'img',
        'ul', 'ol', 'li', 'table', 'tr', 'td', 'th'
    ];

    private array $allowedAttributes = [
        'class', 'id', 'src', 'alt', 'title', 'data-*'
    ];

    public function validateTemplate(string $name, array $structure): void
    {
        if (!$this->isValidTemplateName($name)) {
            throw new TemplateSecurityException("Invalid template name");
        }

        if (!$this->hasValidStructure($structure)) {
            throw new TemplateSecurityException("Invalid template structure");
        }
    }

    public function sanitizeContent(string $content): string
    {
        return $this->sanitizeHtml(
            $this->removeUnsafeTags(
                $this->filterAttributes($content)
            )
        );
    }

    public function validateMediaSource(string $src): bool
    {
        return filter_var($src, FILTER_VALIDATE_URL) && 
               $this->isAllowedDomain($src);
    }

    public function validateComponentProps(array $props): array
    {
        return array_map(
            fn($value) => is_string($value) ? $this->sanitizeString($value) : $value,
            array_filter($props, [$this, 'isAllowedProp'])
        );
    }

    private function isValidTemplateName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9\-_.]+$/', $name);
    }

    private function hasValidStructure(array $structure): bool
    {
        return isset($structure['sections']) &&
               is_array($structure['sections']) &&
               !empty($structure['view']);
    }

    private function removeUnsafeTags(string $content): string
    {
        return strip_tags($content, $this->getAllowedTagsString());
    }

    private function filterAttributes(string $content): string
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $this->walkAndFilterNodes($dom->documentElement);
        
        return $dom->saveHTML();
    }

    private function walkAndFilterNodes(\DOMNode $node): void
    {
        if ($node->hasAttributes()) {
            $this->filterNodeAttributes($node);
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $this->walkAndFilterNodes($child);
            }
        }
    }

    private function filterNodeAttributes(\DOMNode $node): void
    {
        $attributes = [];
        foreach ($node->attributes as $attr) {
            if ($this->isAllowedAttribute($attr->name)) {
                $attributes[$attr->name] = $this->sanitizeAttributeValue($attr->value);
            }
        }

        foreach ($node->attributes as $attr) {
            $node->removeAttribute($attr->name);
        }

        foreach ($attributes as $name => $value) {
            $node->setAttribute($name, $value);
        }
    }

    private function sanitizeAttributeValue(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    private function isAllowedAttribute(string $name): bool
    {
        return in_array($name, $this->allowedAttributes) ||
               preg_match('/^data-[a-zA-Z0-9\-]+$/', $name);
    }

    private function getAllowedTagsString(): string
    {
        return '<' . implode('><', $this->allowedTags) . '>';
    }

    private function isAllowedDomain(string $url): bool
    {
        $whitelist = config('template.allowed_domains', []);
        $domain = parse_url($url, PHP_URL_HOST);
        
        return in_array($domain, $whitelist);
    }

    private function isAllowedProp($value): bool
    {
        return !is_object($value) && 
               !is_resource($value) && 
               !is_callable($value);
    }

    private function sanitizeString(string $value): string
    {
        return htmlspecialchars(strip_tags($value), ENT_QUOTES);
    }
}
