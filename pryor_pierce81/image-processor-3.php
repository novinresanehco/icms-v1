<?php

namespace App\Core\Image;

class ImageProcessor
{
    private array $filters = [];
    private array $optimizers = [];
    private ImageValidator $validator;

    public function process(Image $image, array $operations): ProcessedImage
    {
        $this->validator->validate($image);
        $processedImage = clone $image;

        foreach ($operations as $operation) {
            $processedImage = $this->applyOperation($processedImage, $operation);
        }

        return $this->optimize($processedImage);
    }

    private function applyOperation(Image $image, Operation $operation): Image
    {
        $filter = $this->filters[$operation->getType()]
            ?? throw new ImageProcessingException("Unknown operation: {$operation->getType()}");

        return $filter->apply($image, $operation->getParameters());
    }

    private function optimize(Image $image): ProcessedImage
    {
        foreach ($this->optimizers as $optimizer) {
            $image = $optimizer->optimize($image);
        }
        return new ProcessedImage($image);
    }

    public function addFilter(string $type, ImageFilter $filter): void
    {
        $this->filters[$type] = $filter;
    }

    public function addOptimizer(ImageOptimizer $optimizer): void
    {
        $this->optimizers[] = $optimizer;
    }
}

class Image
{
    private string $path;
    private string $mime;
    private int $width;
    private int $height;
    private $resource;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->loadImage();
    }

    private function loadImage(): void
    {
        if (!file_exists($this->path)) {
            throw new ImageException("Image not found: {$this->path}");
        }

        $info = getimagesize($this->path);
        if ($info === false) {
            throw new ImageException("Invalid image: {$this->path}");
        }

        [$this->width, $this->height, $type] = $info;
        $this->mime = $info['mime'];
        
        $this->resource = match($this->mime) {
            'image/jpeg' => imagecreatefromjpeg($this->path),
            'image/png'  => imagecreatefrompng($this->path),
            'image/gif'  => imagecreatefromgif($this->path),
            default      => throw new ImageException("Unsupported image type: {$this->mime}")
        };
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function save(string $path, int $quality = 90): void
    {
        match($this->mime) {
            'image/jpeg' => imagejpeg($this->resource, $path, $quality),
            'image/png'  => imagepng($this->resource, $path, (int) ($quality / 10)),
            'image/gif'  => imagegif($this->resource, $path)
        };
    }

    public function __destruct()
    {
        if ($this->resource) {
            imagedestroy($this->resource);
        }
    }
}

class Operation
{
    private string $type;
    private array $parameters;

    public function __construct(string $type, array $parameters = [])
    {
        $this->type = $type;
        $this->parameters = $parameters;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}

interface ImageFilter
{
    public function apply(Image $image, array $parameters): Image;
}

interface ImageOptimizer
{
    public function optimize(Image $image): Image;
}

class ResizeFilter implements ImageFilter
{
    public function apply(Image $image, array $parameters): Image
    {
        $width = $parameters['width'] ?? null;
        $height = $parameters['height'] ?? null;
        $maintainAspectRatio = $parameters['maintain_aspect_ratio'] ?? true;

        if ($maintainAspectRatio) {
            if ($width === null) {
                $width = $height * ($image->getWidth() / $image->getHeight());
            } elseif ($height === null) {
                $height = $width * ($image->getHeight() / $image->getWidth());
            }
        }

        $width = (int) $width;
        $height = (int) $height;

        $newResource = imagecreatetruecolor($width, $height);
        imagecopyresampled(
            $newResource,
            $image->getResource(),
            0, 0, 0, 0,
            $width, $height,
            $image->getWidth(),
            $image->getHeight()
        );

        $newImage = clone $image;
        imagedestroy($image->getResource());
        $newImage->setResource($newResource);

        return $newImage;
    }
}

class CropFilter implements ImageFilter
{
    public function apply(Image $image, array $parameters): Image
    {
        $x = $parameters['x'] ?? 0;
        $y = $parameters['y'] ?? 0;
        $width = $parameters['width'] ?? $image->getWidth();
        $height = $parameters['height'] ?? $image->getHeight();

        $newResource = imagecreatetruecolor($width, $height);
        imagecopy(
            $newResource,
            $image->getResource(),
            0, 0, $x, $y,
            $width, $height
        );

        $newImage = clone $image;
        imagedestroy($image->getResource());
        $newImage->setResource($newResource);

        return $newImage;
    }
}

class WatermarkFilter implements ImageFilter
{
    public function apply(Image $image, array $parameters): Image
    {
        $watermarkPath = $parameters['path'];
        $position = $parameters['position'] ?? 'center';
        $opacity = $parameters['opacity'] ?? 50;

        $watermark = new Image($watermarkPath);
        $watermarkResource = $watermark->getResource();

        $x = $y = 0;
        switch ($position) {
            case 'top-left':
                break;
            case 'top-right':
                $x = $image->getWidth() - $watermark->getWidth();
                break;
            case 'bottom-left':
                $y = $image->getHeight() - $watermark->getHeight();
                break;
            case 'bottom-right':
                $x = $image->getWidth() - $watermark->getWidth();
                $y = $image->getHeight() - $watermark->getHeight();
                break;
            case 'center':
            default:
                $x = ($image->getWidth() - $watermark->getWidth()) / 2;
                $y = ($image->getHeight() - $watermark->getHeight()) / 2;
        }

        imagecopymerge(
            $image->getResource(),
            $watermarkResource,
            (int) $x, (int) $y,
            0, 0,
            $watermark->getWidth(),
            $watermark->getHeight(),
            $opacity
        );

        return $image;
    }
}

class ProcessedImage extends Image
{
    private array $metadata;

    public function __construct(Image $image)
    {
        parent::__construct($image->getPath());
        $this->metadata = [
            'processed_at' => time(),
            'original_size' => filesize($image->getPath()),
            'processed_size' => filesize($this->getPath())
        ];
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class ImageException extends \Exception {}
class ImageProcessingException extends ImageException {}
