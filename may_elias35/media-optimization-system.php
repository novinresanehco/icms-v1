// File: app/Core/Media/Optimization/ImageOptimizer.php
<?php

namespace App\Core\Media\Optimization;

class ImageOptimizer
{
    protected array $optimizers;
    protected OptimizationConfig $config;
    protected MetricsCollector $metrics;

    public function optimize(Image $image, array $settings = []): Image
    {
        $settings = array_merge($this->config->getDefaultSettings(), $settings);
        $originalSize = $image->filesize();

        foreach ($this->optimizers as $optimizer) {
            if ($optimizer->canOptimize($image)) {
                $image = $optimizer->optimize($image, $settings);
            }
        }

        $this->metrics->recordOptimization(
            $image, 
            $originalSize, 
            $image->filesize()
        );

        return $image;
    }

    public function getMetrics(): array
    {
        return $this->metrics->getMetrics();
    }
}

// File: app/Core/Media/Optimization/Optimizers/JpegOptimizer.php
<?php

namespace App\Core\Media\Optimization\Optimizers;

class JpegOptimizer implements ImageOptimizerInterface
{
    protected array $defaultSettings = [
        'quality' => 85,
        'progressive' => true,
        'strip_metadata' => true
    ];

    public function optimize(Image $image, array $settings = []): Image
    {
        $settings = array_merge($this->defaultSettings, $settings);

        return $image
            ->quality($settings['quality'])
            ->interlace($settings['progressive'])
            ->stripMetadata($settings['strip_metadata']);
    }

    public function canOptimize(Image $image): bool
    {
        return $image->mime() === 'image/jpeg';
    }
}
