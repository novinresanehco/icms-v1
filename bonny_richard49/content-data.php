<?php

namespace App\Core\Content\DTO;

use DateTime;
use JsonSerializable;

class ContentData implements JsonSerializable
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

    /**
     * Create a new ContentData instance.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->title = $data['title'];
        $this->content = $data['content'];
        $this->excerpt = $data['excerpt'] ?? null;
        $this->status = $data['status'] ?? 'draft';
        $this->categoryId = $data['category