<?php

namespace App\Core\Notifications;

class NotificationResult
{
    private bool $success;
    private ?string $messageId;
    private ?array $metadata;
    private ?string $error;

    public function __construct(
        bool $success,
        ?string $messageId = null,
        ?array $metadata = null,
        ?string $error = null
    ) {
        $this->success = $success;
        $this->messageId = $messageId;
        $this->metadata = $metadata;
        $this->error = $error;
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message_id' => $this->messageId,
            'metadata' => $this->metadata,
            'error' => $this->error
        ];
    }

    public static function success(string $messageId, array $metadata = []): self
    {
        return new self(true, $messageId, $metadata);
    }

    public static function failure(string $error, array $metadata = []): self
    {
        return new self(false, null, $metadata, $error);
    }
}
