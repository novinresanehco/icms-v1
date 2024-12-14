<?php

namespace App\Core\Logging\ValueObjects;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

class LogEntry implements Arrayable, Jsonable
{
    private string $id;
    private Carbon $timestamp;
    private string $level;
    private string $message;
    private array $context;
    private array $extra;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? (string) Str::uuid();
        $this->timestamp = $data['timestamp'] ?? now();
        $this->level = $data['level'];
        $this->message = $data['message'];
        $this->context = $data['context'] ?? [];
        $this->extra = $data['extra'] ?? [];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTimestamp(): Carbon
    {
        return $this->timestamp;
    }

    public function getLevel(): string
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

    public function addContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    public function addExtra(array $extra): void
    {
        $this->extra = array_merge($this->extra, $extra);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp->toIso8601String(),
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'extra' => $this->extra,
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }
}
