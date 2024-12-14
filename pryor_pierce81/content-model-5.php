<?php

namespace App\Core\CMS\Models;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ContentException;
use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    protected $table = 'contents';
    
    protected $fillable = [
        'title',
        'content',
        'status',
        'author_id',
        'meta_data'
    ];

    protected $casts = [
        'meta_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'published_at' => 'datetime'
    ];

    public function validate(): bool
    {
        $this->validateTitle();
        $this->validateContent();
        $this->validateStatus();
        $this->validateMetadata();
        
        return true;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            throw new ContentException('Invalid content status');
        }

        if ($status === 'published' && $this->status !== 'published') {
            $this->published_at = now();
        }

        $this->status = $status;
    }

    public function publish(): void
    {
        $this->setStatus('published');
        $this->published_at = now();
    }

    public function archive(): void
    {
        $this->setStatus('archived');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    private function validateTitle(): void
    {
        if (empty($this->title)) {
            throw new ContentException('Content title is required');
        }

        if (strlen($this->title) > 255) {
            throw new ContentException('Content title exceeds maximum length');
        }
    }

    private function validateContent(): void
    {
        if (empty($this->content)) {
            throw new ContentException('Content body is required');
        }

        if (strlen($this->content) > 1048576) { // 1MB
            throw new ContentException('Content body exceeds maximum length');
        }
    }

    private function validateStatus(): void
    {
        if (!in_array($this->status, ['draft', 'published', 'archived'])) {
            throw new ContentException('Invalid content status');
        }
    }

    private function validateMetadata(): void
    {
        if (!empty($this->meta_data) && !is_array($this->meta_data)) {
            throw new ContentException('Invalid content metadata');
        }
    }
}
