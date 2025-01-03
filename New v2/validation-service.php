<?php

namespace App\Core\Services;

use App\Core\Security\AuditService;
use App\Exceptions\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException as LaravelValidationException;

class ValidationService
{
    protected AuditService $auditService;
    protected array $customRules = [];
    protected array $customMessages = [];

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
        $this->registerCustomRules();
    }

    public function validate(array $data, array $rules, array $messages = []): array
    {
        try {
            $validator = Validator::make(
                $data,
                $rules,
                array_merge($this->customMessages, $messages)
            );

            if ($validator->fails()) {
                throw new ValidationException($validator->errors()->first());
            }

            return $validator->validated();

        } catch (LaravelValidationException $e) {
            $this->auditService->logSecurityEvent('validation_failed', [
                'errors' => $e->errors()
            ]);
            throw new ValidationException($e->getMessage(), 0, $e);
        }
    }

    public function validateContent(array $data, ?Content $existing = null): array
    {
        $rules = [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'type' => 'required|string|in:page,post,article',
            'status' => 'required|string|in:draft,published,archived',
            'categories' => 'sometimes|array',
            'categories.*' => 'integer|exists:categories,id',
            'media' => 'sometimes|array',
            'media.*' => 'integer|exists:media,id',
            'metadata' => 'sometimes|array',
            'published_at' => 'nullable|date',
            'author_id' => 'required|integer|exists:users,id'
        ];

        if ($existing) {
            $rules['slug'] = "sometimes|string|unique:contents,slug,{$existing->id}";
        } else {
            $rules['slug'] = 'required|string|unique:contents,slug';
        }

        return $this->validate($data, $rules);
    }

    public function validateTemplate(array $data): array
    {
        return $this->validate($data, [
            'name' => 'required|string|max:100|unique:templates,name',
            'content' => 'required|string',
            'description' => 'sometimes|string',
            'type' => 'required|string|in:page,email,partial',
            'is_active' => 'sometimes|boolean',
            'requires_compilation' => 'sometimes|boolean',
            'metadata' => 'sometimes|array'
        ]);
    }

    public function validateMedia(array $data): array
    {
        return $this->validate($data, [
            'filename' => 'required|string|max:255',
            'path' => 'required|string',
            'mime_type' => 'required|string',
            'size' => 'required|integer',
            'metadata' => 'sometimes|array'
        ]);
    }

    protected function registerCustomRules(): void
    {
        Validator::extend('slug', function($attribute, $value) {
            return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value);
        });

        $this->customMessages['slug'] = 'The :attribute must be a valid URL slug';

        Validator::extend('safe_html', function($attribute, $value) {
            return $this->validateSafeHtml($value);
        });

        $this->customMessages['safe_html'] = 'The :attribute contains unsafe HTML';
    }

    protected function validateSafeHtml(string $html): bool
    {
        $allowedTags = [
            'p', 'br', 'b', 'i', 'em', 'strong', 'a', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'table', 'tr', 'td'
        ];

        $allowedAttributes = [
            'href', 'src', 'alt', 'title', 'class'
        ];

        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $elements = $dom->getElementsByTagName('*');

        foreach ($elements as $element) {
            if (!in_array($element->tagName, $allowedTags)) {
                return false;
            }

            foreach ($element->attributes as $attribute) {
                if (!in_array($attribute->name, $allowedAttributes)) {
                    return false;
                }

                if ($attribute->name === 'src' || $attribute->name === 'href') {
                    if (!$this->validateUrl($attribute->value)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    protected function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
