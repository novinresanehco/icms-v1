<?php

namespace App\Core\Notification\Analytics\Serialization;

class AnalyticsSerializer
{
    private array $serializers = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_format' => 'json',
            'compression' => true,
            'pretty_print' => false
        ], $config);

        $this->initializeSerializers();
    }

    public function serialize($data, string $format = null): string
    {
        $format = $format ?? $this->config['default_format'];
        
        if (!isset($this->serializers[$format])) {
            throw new \InvalidArgumentException("Unsupported format: {$format}");
        }

        $serialized = $this->serializers[$format]->serialize($data);

        if ($this->config['compression']) {
            $serialized = $this->compress($serialized);
        }

        return $serialized;
    }

    public function deserialize(string $data, string $format = null)
    {
        $format = $format ?? $this->config['default_format'];
        
        if (!isset($this->serializers[$format])) {
            throw new \InvalidArgumentException("Unsupported format: {$format}");
        }

        if ($this->config['compression']) {
            $data = $this->decompress($data);
        }

        return $this->serializers[$format]->deserialize($data);
    }

    public function addSerializer(string $format, Serializer $serializer): void
    {
        $this->serializers[$format] = $serializer;
    }

    private function initializeSerializers(): void
    {
        $this->serializers = [
            'json' => new JsonSerializer($this->config),
            'msgpack' => new MsgPackSerializer($this->config),
            'php' => new PhpSerializer($this->config)
        ];
    }

    private function compress(string $data): string
    {
        return gzencode($data, 9);
    }

    private function decompress(string $data): string
    {
        return gzdecode($data);
    }
}

interface Serializer
{
    public function serialize($data): string;
    public function deserialize(string $data);
}

class JsonSerializer implements Serializer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function serialize($data): string
    {
        $options = JSON_THROW_ON_ERROR;
        
        if ($this->config['pretty_print']) {
            $options |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $options);
    }

    public function deserialize(string $data)
    {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}

class MsgPackSerializer implements Serializer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function serialize($data): string
    {
        return msgpack_pack($data);
    }

    public function deserialize(string $data)
    {
        return msgpack_unpack($data);
    }
}

class PhpSerializer implements Serializer
{
    private array $config;
    private array $allowedClasses;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->allowedClasses = $config['allowed_classes'] ?? [];
    }

    public function serialize($data): string
    {
        return serialize($data);
    }

    public function deserialize(string $data)
    {
        return unserialize($data, ['allowed_classes' => $this->allowedClasses]);
    }
}

class SerializationContext
{
    private array $attributes = [];
    private array $references = [];
    private int $depth = 0;
    private int $maxDepth;

    public function __construct(int $maxDepth = 100)
    {
        $this->maxDepth = $maxDepth;
    }

    public function enter(): void
    {
        $this->depth++;
        if ($this->depth > $this->maxDepth) {
            throw new \RuntimeException('Max depth exceeded during serialization');
        }
    }

    public function leave(): void
    {
        $this->depth--;
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function addReference(string $id, $object): void
    {
        $this->references[$id] = $object;
    }

    public function hasReference(string $id): bool
    {
        return isset($this->references[$id]);
    }

    public function getReference(string $id)
    {
        return $this->references[$id] ?? null;
    }
}
