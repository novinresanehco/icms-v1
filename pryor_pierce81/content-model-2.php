<?php

namespace App\Core\CMS;

class Content
{
    private string $id;
    private string $title;
    private string $body;
    private array $metadata;
    private ContentStatus $status;
    private array $media = [];
    private string $createdBy;
    private string $updatedBy;
    private ?string $deletedBy = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(array $data)
    {
        $this->id = uniqid('content_', true);
        $this->title = $data['title'];
        $this->body = $data['body'];
        $this->metadata = $data['metadata'] ?? [];
        $this->status = ContentStatus::DRAFT;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function update(array $data): void
    {
        if (isset($data['title'])) {
            $this->title = $data['title'];
        }
        if (isset($data['body'])) {
            $this->body = $data['body'];
        }
        if (isset($data['metadata'])) {
            $this->metadata = array_merge($this->metadata, $data['metadata']);
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setStatus(ContentStatus $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    public function attachMedia(array $media): void
    {
        $this->media = array_merge($this->media, $media);
    }

    public function clearMedia(): void
    {
        $this->media = [];
    }

    public function setCreatedBy(string $userId): void
    {
        $this->createdBy = $userId;
    }

    public function setUpdatedBy(string $userId): void
    {
        $this->updatedBy = $userId;
    }

    public function setDeletedBy(string $userId): void
    {
        $this->deletedBy = $userId;
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'metadata' => $this->metadata,
            'status' => $this->status->value,
            'media' => $this->media,
            'created_by' => $this->createdBy,
            'updated_by' => $this->updatedBy,
            'deleted_by' => $this->deletedBy,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'deleted_at' => $this->deletedAt?->format('Y-m-d H:i:s')
        ];
    }
}
