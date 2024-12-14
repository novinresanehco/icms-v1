<?php

namespace App\Core\Logging\Models;

use App\Core\Logging\ValueObjects\LogLevel;
use DateTimeInterface;
use JsonSerializable;

class LogEntry implements JsonSerializable
{
    private string $id;
    private DateTimeInterface $timestamp;
    private LogLevel $level;
    private string $message;
    private array $context;
    private array $extra;
    private array $metadata;

    public function __construct(
        string $id,
        DateTimeInterface $timestamp,
        LogLevel $level,
        string $message,
        array $context = [],
        array $extra = [],
        array $metadata = []
    ) {
        $this->id = $id;
        $this->timestamp = $timestamp;
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
        $this->extra = $extra;
        $this->metadata = $metadata;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTimestamp(): DateTimeInterface
    {
        return $this->timestamp;
    }

    public function getLevel(): LogLevel
    {
        return $this->level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);
        return $clone;
    }

    public function withExtra(array $extra): self
    {
        $clone = clone $this;
        $clone->extra = array_merge($this->extra, $extra);
        return $clone;
    }

    public function withMetadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->metadata = array_merge($this->metadata, $metadata);
        return $clone;
    }

    public function isLevel(LogLevel $level): bool
    {
        return $this->level === $level;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp->format(DateTimeInterface::ISO8601),
            'level' => $this->level->value,
            'message' => $this->message,
            'context' => $this->context,
            'extra' => $this->extra,
            'metadata' => $this->metadata
        ];
    }