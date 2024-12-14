<?php

namespace App\Core\Notification\Analytics\Compression;

class AnalyticsCompressor
{
    private array $algorithms = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_algorithm' => 'gzip',
            'compression_level' => 6,
            'chunk_size' => 8192
        ], $config);

        $this->initializeAlgorithms();
    }

    public function compress($data, string $algorithm = null): string
    {
        $algorithm = $algorithm ?? $this->config['default_algorithm'];

        if (!isset($this->algorithms[$algorithm])) {
            throw new \InvalidArgumentException("Unsupported compression algorithm: {$algorithm}");
        }

        return $this->algorithms[$algorithm]->compress($data);
    }

    public function decompress(string $data, string $algorithm = null): string
    {
        $algorithm = $algorithm ?? $this->config['default_algorithm'];

        if (!isset($this->algorithms[$algorithm])) {
            throw new \InvalidArgumentException("Unsupported compression algorithm: {$algorithm}");
        }

        return $this->algorithms[$algorithm]->decompress($data);
    }

    public function addAlgorithm(string $name, CompressionAlgorithm $algorithm): void
    {
        $this->algorithms[$name] = $algorithm;
    }

    private function initializeAlgorithms(): void
    {
        $this->algorithms = [
            'gzip' => new GzipCompression($this->config),
            'bzip2' => new Bzip2Compression($this->config),
            'lzf' => new LzfCompression($this->config)
        ];
    }
}

interface CompressionAlgorithm
{
    public function compress($data): string;
    public function decompress(string $data): string;
}

class GzipCompression implements CompressionAlgorithm
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function compress($data): string
    {
        return gzencode($data, $this->config['compression_level']);
    }

    public function decompress(string $data): string
    {
        return gzdecode($data);
    }
}

class Bzip2Compression implements CompressionAlgorithm
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function compress($data): string
    {
        return bzcompress($data, $this->config['compression_level']);
    }

    public function decompress(string $data): string
    {
        return bzdecompress($data);
    }
}

class LzfCompression implements CompressionAlgorithm
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function compress($data): string
    {
        return lzf_compress($data);
    }

    public function decompress(string $data): string
    {
        return lzf_decompress($data);
    }
}

class StreamCompressor
{
    private CompressionAlgorithm $algorithm;
    private int $chunkSize;
    private $inputHandle;
    private $outputHandle;

    public function __construct(CompressionAlgorithm $algorithm, int $chunkSize = 8192)
    {
        $this->algorithm = $algorithm;
        $this->chunkSize = $chunkSize;
    }

    public function compressStream($input, $output): void
    {
        $this->inputHandle = $input;
        $this->outputHandle = $output;

        while (!feof($this->inputHandle)) {
            $chunk = fread($this->inputHandle, $this->chunkSize);
            $compressed = $this->algorithm->compress($chunk);
            fwrite($this->outputHandle, pack('N', strlen($compressed)) . $compressed);
        }
    }

    public function decompressStream($input, $output): void
    {
        $this->inputHandle = $input;
        $this->outputHandle = $output;

        while (!feof($this->inputHandle)) {
            $sizeData = fread($this->inputHandle, 4);
            if (strlen($sizeData) < 4) {
                break;
            }

            $size = unpack('N', $sizeData)[1];
            $compressed = fread($this->inputHandle, $size);
            
            if (strlen($compressed) < $size) {
                break;
            }

            $decompressed = $this->algorithm->decompress($compressed);
            fwrite($this->outputHandle, $decompressed);
        }
    }
}

class CompressionMetrics
{
    private array $metrics = [];

    public function recordCompression(string $algorithm, int $originalSize, int $compressedSize, float $duration): void
    {
        if (!isset($this->metrics[$algorithm])) {
            $this->metrics[$algorithm] = [
                'total_original_size' => 0,
                'total_compressed_size' => 0,
                'total_duration' => 0,
                'compression_count' => 0
            ];
        }

        $this->metrics[$algorithm]['total_original_size'] += $originalSize;
        $this->metrics[$algorithm]['total_compressed_size'] += $compressedSize;
        $this->metrics[$algorithm]['total_duration'] += $duration;
        $this->metrics[$algorithm]['compression_count']++;
    }

    public function getMetrics(string $algorithm = null): array
    {
        if ($algorithm !== null) {
            return $this->calculateMetrics($algorithm);
        }

        $result = [];
        foreach ($this->metrics as $alg => $data) {
            $result[$alg] = $this->calculateMetrics($alg);
        }
        return $result;
    }

    private function calculateMetrics(string $algorithm): array
    {
        $data = $this->metrics[$algorithm];
        
        return [
            'compression_ratio' => $data['total_compressed_size'] / $data['total_original_size'],
            'average_duration' => $data['total_duration'] / $data['compression_count'],
            'total_savings' => $data['total_original_size'] - $data['total_compressed_size'],
            'compression_count' => $data['compression_count']
        ];
    }
}
