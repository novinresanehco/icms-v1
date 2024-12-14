<?php

namespace App\Core\Notification\Analytics\Encoder;

class DataEncoder
{
    private array $encoders = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'compression' => true,
            'max_length' => 1000
        ], $config);
    }

    public function addEncoder(string $name, EncoderInterface $encoder): void
    {
        $this->encoders[$name] = $encoder;
    }

    public function encode(array $data, string $encoderName, array $options = []): string
    {
        if (!isset($this->encoders[$encoderName])) {
            throw new \InvalidArgumentException("Unknown encoder: {$encoderName}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->encoders[$encoderName]->encode($data, array_merge($this->config, $options));
            $this->recordMetrics($encoderName, strlen(serialize($data)), strlen($result), microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($encoderName, strlen(serialize($data)), 0, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function decode(string $data, string $encoderName, array $options = []): array
    {
        if (!isset($this->encoders[$encoderName])) {
            throw new \InvalidArgumentException("Unknown encoder: {$encoderName}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->encoders[$encoderName]->decode($data, array_merge($this->config, $options));
            $this->recordMetrics($encoderName . '_decode', strlen($data), strlen(serialize($result)), microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($encoderName . '_decode', strlen($data), 0, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $operation, int $inputSize, int $outputSize, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'total_operations' => 0,
                'successful_operations' => 0,
                'failed_operations' => 0,
                'total_duration' => 0,
                'total_input_size' => 0,
                'total_output_size' => 0,
                'compression_ratio' => 0
            ];
        }

        $metrics = &$this->metrics[$operation];
        $metrics['total_operations']++;
        $metrics[$success ? 'successful_operations' : 'failed_operations']++;
        $metrics['total_duration'] += $duration;
        $metrics['total_input_size'] += $inputSize;
        $metrics['total_output_size'] += $outputSize;
        
        if ($inputSize > 0) {
            $metrics['compression_ratio'] = 1 - ($metrics['total_output_size'] / $metrics['total_input_size']);
        }
    }
}

interface EncoderInterface
{
    public function encode(array $data, array $options = []): string;
    public function decode(string $data, array $options = []): array;
}

class JsonEncoder implements EncoderInterface
{
    public function encode(array $data, array $options = []): string
    {
        $flags = JSON_THROW_ON_ERROR;
        if ($options['pretty'] ?? false) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode($data, $flags);

        if ($options['compression'] ?? false) {
            $encoded = gzcompress($encoded, 9);
        }

        return $encoded;
    }

    public function decode(string $data, array $options = []): array
    {
        if ($options['compression'] ?? false) {
            $data = gzuncompress($data);
        }

        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}

class BinaryEncoder implements EncoderInterface
{
    public function encode(array $data, array $options = []): string
    {
        $encoded = serialize($data);

        if ($options['compression'] ?? false) {
            $encoded = gzcompress($encoded, 9);
        }

        return base64_encode($encoded);
    }

    public function decode(string $data, array $options = []): array
    {
        $decoded = base64_decode($data, true);
        
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 encoding');
        }

        if ($options['compression'] ?? false) {
            $decoded = gzuncompress($decoded);
        }

        $result = unserialize($decoded);
        
        if (!is_array($result)) {
            throw new \RuntimeException('Decoded data is not an array');
        }

        return $result;
    }
}

class MessagePackEncoder implements EncoderInterface
{
    public function encode(array $data, array $options = []): string
    {
        $encoded = msgpack_pack($data);

        if ($options['compression'] ?? false) {
            $encoded = gzcompress($encoded, 9);
        }

        return $encoded;
    }

    public function decode(string $data, array $options = []): array
    {
        if ($options['compression'] ?? false) {
            $data = gzuncompress($data);
        }

        $decoded = msgpack_unpack($data);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Decoded data is not an array');
        }

        return $decoded;
    }
}
