<?php

namespace App\Core\Content\DTO;

use DateTime;
use JsonSerializable;
use App\Core\Shared\DTO\DataTransferObject;

class ContentData extends DataTransferObject implements JsonSerializable
{
    public string $title;
    public string $content;
    public ?string $excerpt;
    public string $status;
    public int $categoryId;
    public int $authorId;
    public ?DateTime $publishedAt;
    public bool $isFeatured;
    public ?string $metaTitle;
    public ?string $metaDescription;
    public ?string $metaKeywords;
    public array $tags;
    public array $media;
    public array $attributes;

    public function __construct(array $data)
    {
        $this->title = $data['title'];
        $this->content = $data['content'];
        $this->excerpt = $data['excerpt'] ?? null;
        $this->status = $data['status'] ?? 'draft';
        $this->categoryId = (int) $data['category_id'];
        $this->authorId = (int) $data['author_id'];
        $this->publishedAt = isset($data['published_at']) ? new DateTime($data['published_at']) : null;
        $this->isFeatured = $data['is_featured'] ?? false;
        $this->metaTitle = $data['meta_title'] ?? null;
        $this->metaDescription = $data['meta_description'] ?? null;
        $this->metaKeywords = $data['meta_keywords'] ?? null;
        $this->tags = $data['tags'] ?? [];
        $this->media = $data['media'] ?? [];
        $this->attributes = $data['attributes'] ?? [];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->title)) {
            $errors['title'] = 'Title is required';
        }

        if (empty($this->content)) {
            $errors['content'] = 'Content is required';
        }

        if (empty($this->categoryId)) {
            $errors['category_id'] = 'Category is required';
        }

        if (empty($this->authorId)) {
            $errors['author_id'] = 'Author is required';
        }

        if (!in_array($this->status, ['draft', 'published', 'archived'])) {
            $errors['status'] = 'Invalid status';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
            'status' => $this->status,
            'category_id' => $this->categoryId,
            'author_id' => $this->authorId,
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s'),
            'is_featured' => $this->isFeatured,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'meta_keywords' => $this->metaKeywords,
            'tags' => $this->tags,
            'media' => $this->media,
            'attributes' => $this->attributes,
        ];
    }
}
