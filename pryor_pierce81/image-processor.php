<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ImageProcessingException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class ImageProcessor implements ImageProcessorInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private ImageManager $imageManager;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        ImageManager $imageManager,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->imageManager = $imageManager;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function process(MediaFile $file, array $operations): MediaFile
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('image:process', [
                'operation_id' => $operationId,
                'media_id' => $file->getId()
            ]);

            $this->validateImageFile($file);
            $processedFile = $this->executeImageOperations($file, $operations);
            
            $this->logImageOperation($operationId, 'process', $file->getId());
            
            return $processedFile;

        } catch (\Exception $e) {
            $this->handleProcessingFailure($operationId, 'process', $e);
            throw new ImageProcessingException('Image processing failed', 0, $e);
        }
    }

    private function validateImageFile(MediaFile $file): void
    {
        if (!$this->isImageFile($file)) {
            throw new ImageProcessingException('Invalid image file');
        }

        if (!$this->validateImageDimensions($file)) {
            throw new ImageProcessingException('Image dimensions exceed limits');
        }

        if (!$this->validateImageContent($file)) {
            throw new ImageProcessingException('Image content validation failed');
        }
    }

    private function executeImageOperations(MediaFile $file, array $operations): MediaFile
    {
        $image = $this->imageManager->make(
            Storage::get($file->getPath())
        );

        foreach ($operations as $operation) {
            $this->executeImageOperation($image, $operation);
        }

        $processedPath = $this->saveProcessedImage($image, $file);
        
        return new MediaFile([
            'id' => $this->generateFileId(),
            'name' => $this->generateProcessedFileName($file),
            'path' => $processedPath,
            'mime_type' => $image->mime(),
            'size' => Storage::size($processedPath),
            'metadata' => $this->extractImageMetadata($image),
            'security_hash' => $this->generateSecurityHash($processedPath)
        ]);
    }

    private function executeImageOperation($image, array $operation): void
    {
        switch ($operation['type']) {
            case 'resize':
                $this->resizeImage($image, $operation);
                break;
            case 'crop':
                $this->cropImage($image, $operation);
                break;
            case 'watermark':
                $this->addWatermark($image, $operation);
                break;
            case 'filter':
                $this->applyFilter($image, $operation);
                break;
            default:
                throw new ImageProcessingException('Unsupported image operation');
        }
    }

    private function resizeImage($image, array $options): void
    {
        $width = $options['width'] ?? null;
        $height = $options['height'] ?? null;
        
        if ($width > $this->config['max_dimensions']['width'] ||
            $height > $this->config['max_dimensions']['height']) {
            throw new ImageProcessingException('Resize dimensions exceed limits');
        }

        $image->resize($width, $height, function($constraint) use ($options) {
            if ($options['maintain_aspect'] ?? true) {
                $constraint->aspectRatio();
            }
            if ($options['prevent_upsizing'] ?? true) {
                $constraint->upsize();
            }
        });
    }

    private function cropImage($image, array $options): void
    {
        $width = $options['width'];
        $height = $options['height'];
        $x = $options['x'] ?? 0;
        $y = $options['y'] ?? 0;

        if ($width > $this->config['max_dimensions']['width'] ||
            $height > $this->config['max_dimensions']['height']) {
            throw new ImageProcessingException('Crop dimensions exceed limits');
        }

        $image->crop($width, $height, $x, $y);
    }

    private function addWatermark($image, array $options): void
    {
        $watermarkPath = $options['watermark_path'];
        
        if (!Storage::exists($watermarkPath)) {
            throw new ImageProcessingException('Watermark file not found');
        }

        $watermark = $this->imageManager->make(
            Storage::get($watermarkPath)
        );

        $image->insert(
            $watermark,
            $options['position'] ?? 'center',
            $options['x_offset'] ?? 0,
            $options['y_offset'] ?? 0
        );
    }

    private function applyFilter($image, array $options): void
    {
        $filter = $options['filter'];
        
        if (!in_array($filter, $this->config['allowed_filters'])) {
            throw new ImageProcessingException('Unsupported image filter');
        }

        $image->filter($this->createFilter($filter, $options));
    }

    private function saveProcessedImage($image, MediaFile $originalFile): string
    {
        $directory = $this->getSecureDirectory();
        $fileName = $this->generateProcessedFileName($originalFile);
        $path = $directory . '/' . $fileName;

        Storage::put(
            $path,
            $image->stream(null, $this->config['quality'])->getContents()
        );

        return $path;
    }

    private function isImageFile(MediaFile $file): bool
    {
        return in_array(
            $file->getMimeType(),
            $this->config['allowed_mime_types']
        );
    }

    private function validateImageDimensions(MediaFile $file): bool
    {
        $image = $this->imageManager->make(
            Storage::get($file->getPath())
        );

        return $image->width() <= $this->config['max_dimensions']['width'] &&
               $image->height() <= $this->config['max_dimensions']['height'];
    }

    private function validateImageContent(