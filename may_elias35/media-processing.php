<?php

namespace App\Core\Processing;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\OperationMonitor;
use Illuminate\Support\Facades\Log;

class MediaProcessor implements MediaProcessorInterface
{
    private SecurityManager $security;
    private OperationMonitor $monitor;
    private array $config;

    private const MAX_PROCESSING_TIME = 300; // 5 minutes
    private const MAX_MEMORY_USAGE = 256 * 1024 * 1024; // 256MB

    public function __construct(
        SecurityManager $security,
        OperationMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function process(string $path, array $options = []): array
    {
        return $this->security->executeSecureOperation(function() use ($path, $options) {
            // Start monitoring
            $operationId = $this->monitor->startOperation('media_process');
            
            try {
                // Initialize processor
                $processor = $this->initializeProcessor($options);
                
                // Process file
                $result = $this->processFile($processor, $path, $options);
                
                // Validate result
                $this->validateResult($result);
                
                // Optimize if required
                if ($options['optimize'] ?? true) {
                    $result = $this->optimizeResult($result);
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $this->monitor->recordFailure($operationId, $e);
                throw new ProcessingException('Processing failed: ' . $e->getMessage(), 0, $e);
            } finally {
                $this->monitor->endOperation($operationId);
            }
        }, ['operation' => 'media_process']);
    }

    public function createVariant(string $path, string $variant, array $specs): string
    {
        return $this->security->executeSecureOperation(function() use ($path, $variant, $specs) {
            $operationId = $this->monitor->startOperation('variant_creation');
            
            try {
                // Initialize processor
                $processor = $this->initializeProcessor(['variant' => $variant]);
                
                // Create variant
                $variantPath = $this->generateVariant($processor, $path, $specs);
                
                // Validate variant
                $this->validateVariant($variantPath, $specs);
                
                return $variantPath;
                
            } catch (\Exception $e) {
                $this->monitor->recordFailure($operationId, $e);
                throw new ProcessingException('Variant creation failed: ' . $e->getMessage(), 0, $e);
            } finally {
                $this->monitor->endOperation($operationId);
            }
        }, ['operation' => 'variant_create']);
    }

    private function initializeProcessor(array $options): object
    {
        $processor = new ImageProcessor(); // or other processor types
        
        $processor->setMaxExecutionTime(self::MAX_PROCESSING_TIME);
        $processor->setMemoryLimit(self::MAX_MEMORY_USAGE);
        
        if ($options['secure'] ?? true) {
            $processor->enableSecurityFeatures();
        }
        
        return $processor;
    }

    private function processFile(object $processor, string $path, array $options): array
    {
        // Set resource limits
        $this->setResourceLimits();
        
        // Process file
        $result = $processor->process($path, [
            'strip_metadata' => !($options['preserve_metadata'] ?? false),
            'sanitize' => true,
            'optimize' => $options['optimize'] ?? true
        ]);
        
        // Validate resource usage
        $this->validateResourceUsage();
        
        return $result;
    }

    private function generateVariant(object $processor, string $path, array $specs): string
    {
        // Set resource limits
        $this->setResourceLimits();
        
        // Generate variant
        $variantPath = $processor->createVariant($path, [
            'width' => $specs['width'] ?? null,
            'height' => $specs['height'] ?? null,
            'quality' => $specs['quality'] ?? 90,
            'format' => $specs['format'] ?? null
        ]);
        
        // Validate resource usage
        $this->validateResourceUsage();
        
        return $variantPath;
    }

    private function validateResult(array $result): void
    {
        if (!isset($result['path']) || !file_exists($result['path'])) {
            throw new ProcessingException('Invalid processing result');
        }

        if ($result['size'] > $this->config['max_file_size']) {
            throw new ProcessingException('Processed file exceeds size limit');
        }

        if (!$this->validateFileIntegrity($result['path'])) {
            throw new ProcessingException('File integrity check failed');
        }
    }

    private function validateVariant(string $path, array $specs): void
    {
        if (!file_exists($path)) {
            throw new ProcessingException('Variant file not created');
        }

        list($width, $height) = getimagesize($path);

        if (isset($specs['width']) && $width !== $specs['width']) {
            throw new ProcessingException('Variant width mismatch');
        }

        if (isset($specs['height']) && $height !== $specs['height']) {
            throw new ProcessingException('Variant height mismatch');
        }

        if (!$this->validateFileIntegrity($path)) {
            throw new ProcessingException('Variant integrity check failed');
        }
    }

    private function setResourceLimits(): void
    {
        ini_set('max_execution_time', self::MAX_PROCESSING_TIME);
        ini_set('memory_limit', self::MAX_MEMORY_USAGE);
    }

    private function validateResourceUsage(): void
    {
        if (memory_get_peak_usage(true) > self::MAX_MEMORY_USAGE) {
            throw new ProcessingException('Memory limit exceeded');
        }
    }

    private function validateFileIntegrity(string $path): bool
    {
        try {
            $image = new \Imagick($path);
            return $image->valid();
        } catch (\ImagickException $e) {
            return false;
        }
    }

    private function optimizeResult(array $result): array
    {
        $optimizer = new ImageOptimizer();
        
        $optimizedPath = $optimizer->optimize(
            $result['path'],
            ['quality' => $this->config['optimization_quality']]
        );
        
        $result['path'] = $optimizedPath;
        $result['size'] = filesize($optimizedPath);
        
        return $result;
    }
}
