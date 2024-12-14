<?php

namespace App\Services;

use App\Models\Content;
use App\Rules\SlugUnique;
use App\Rules\NoScriptTags;
use App\Rules\ValidMetadata;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ValidationService
{
    protected $rules = [
        'content' => [
            'create' => [
                'title' => 'required|min:3|max:255',
                'slug' => ['required', 'alpha_dash', 'max:255', new SlugUnique],
                'content' => ['required', new NoScriptTags],
                'excerpt' => 'nullable|max:500',
                'category_id' => 'required|exists:categories,id',
                'tags' => 'array',
                'tags.*' => 'string|max:50',
                'status' => 'in:draft,published,pending',
                'metadata' => ['nullable', 'array', new ValidMetadata],
                'featured_image' => 'nullable|image|max:2048',
                'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx',
                'scheduled_at' => 'nullable|date|after:now',
                'template' => 'nullable|string|exists:templates,name'
            ],
            'update' => [
                'title' => 'sometimes|required|min:3|max:255',
                'slug' => ['sometimes', 'required', 'alpha_dash', 'max:255', new SlugUnique],
                'content' => ['sometimes', 'required', new NoScriptTags],
                'excerpt' => 'nullable|max:500',
                'category_id' => 'sometimes|required|exists:categories,id',
                'tags' => 'array',
                'tags.*' => 'string|max:50',
                'status' => 'in:draft,published,pending',
                'metadata' => ['nullable', 'array', new ValidMetadata],
                'featured_image' => 'nullable|image|max:2048',
                'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx',
                'scheduled_at' => 'nullable|date|after:now',
                'template' => 'nullable|string|exists:templates,name'
            ]
        ],
        'category' => [
            'create' => [
                'name' => 'required|min:2|max:50',
                'slug' => ['required', 'alpha_dash', 'max:50', new SlugUnique],
                'description' => 'nullable|max:500',
                'parent_id' => 'nullable|exists:categories,id',
                'metadata' => ['nullable', 'array', new ValidMetadata]
            ],
            'update' => [
                'name' => 'sometimes|required|min:2|max:50',
                'slug' => ['sometimes', 'required', 'alpha_dash', 'max:50', new SlugUnique],
                'description' => 'nullable|max:500',
                'parent_id' => 'nullable|exists:categories,id',
                'metadata' => ['nullable', 'array', new ValidMetadata]
            ]
        ]
    ];

    public function validateContent(array $data, string $action = 'create'): array
    {
        return $this->validate($data, $this->rules['content'][$action]);
    }

    public function validateCategory(array $data, string $action = 'create'): array
    {
        return $this->validate($data, $this->rules['category'][$action]);
    }

    protected function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }
}

namespace App\Services;

use App\Models\Content;
use DOMDocument;
use Illuminate\Support\Str;

class ContentSanitizationService
{
    protected $allowedTags = [
        'p', 'br', 'b', 'i', 'u', 'em', 'strong', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'img', 'figure', 'figcaption', 'div', 'span'
    ];

    protected $allowedAttributes = [
        'a' => ['href', 'title', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'div' => ['class'],
        'span' => ['class']
    ];

    public function sanitize(string $content): string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $this->sanitizeNode($dom->documentElement);

        return $dom->saveHTML();
    }

    protected function sanitizeNode(\DOMNode $node)
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            if (!in_array($node->nodeName, $this->allowedTags)) {
                $textContent = $node->textContent;
                $newNode = $node->ownerDocument->createTextNode($textContent);
                $node->parentNode->replaceChild($newNode, $node);
                return;
            }

            // Remove disallowed attributes
            if ($node->hasAttributes()) {
                $attributes = [];
                foreach ($node->attributes as $attr) {
                    $attributes[] = $attr->nodeName;
                }
                foreach ($attributes as $attr) {
                    if (!isset($this->allowedAttributes[$node->nodeName]) ||
                        !in_array($attr, $this->allowedAttributes[$node->nodeName])) {
                        $node->removeAttribute($attr);
                    }
                }
            }
        }

        if ($node->hasChildNodes()) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                $this->sanitizeNode($child);
            }
        }
    }

    public function sanitizeExcerpt(string $content, int $length = 200): string
    {
        $plainText = strip_tags($content);
        return Str::limit($plainText, $length);
    }
}

namespace App\Services;

use App\Models\Content;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class MediaProcessingService
{
    protected $imageConfig = [
        'thumbnails' => [
            'small' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 300, 'height' => 300],
            'large' => ['width' => 800, 'height' => 600]
        ],
        'quality' => 80,
        'format' => 'jpg'
    ];

    public function processUploadedImage($file, string $path = 'images'): array
    {
        $filename = $this->generateFilename($file);
        $paths = [];

        // Process original
        $image = Image::make($file);
        $paths['original'] = $this->saveImage($image, $path, $filename);

        // Generate thumbnails
        foreach ($this->imageConfig['thumbnails'] as $size => $dimensions) {
            $thumbnail = $image->fit($dimensions['width'], $dimensions['height']);
            $paths[$size] = $this->saveImage($thumbnail, "{$path}/thumbnails/{$size}", $filename);
        }

        return $paths;
    }

    protected function saveImage($image, string $path, string $filename): string
    {
        $fullPath = "{$path}/{$filename}";
        
        Storage::put(
            "public/{$fullPath}",
            $image->encode($this->imageConfig['format'], $this->imageConfig['quality'])
        );

        return $fullPath;
    }

    protected function generateFilename($file): string
    {
        return Str::random(40) . '.' . $this->imageConfig['format'];
    }

    public function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::delete("public/{$path}");
        }
    }
}
