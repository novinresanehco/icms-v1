<?php

namespace App\Core\Media\Processors;

use App\Core\Media\Contracts\MediaProcessorInterface;
use App\Core\Media\Models\Media;
use App\Core\Media\Config\DocumentConfig;
use App\Core\Media\Services\DocumentConversionService;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToImage\Pdf;

class DocumentProcessor implements MediaProcessorInterface
{
    protected DocumentConfig $config;
    protected DocumentConversionService $converter;
    
    protected array $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];

    public function __construct(DocumentConfig $config, DocumentConversionService $converter)
    {
        $this->config = $config;
        $this->converter = $converter;
    }

    public function supports(Media $media): bool
    {
        return in_array($media->mime_type, $this->allowedMimeTypes);
    }

    public function process(Media $media): Media
    {
        try {
            // Convert to PDF if not already
            if ($media->mime_type !== 'application/pdf') {
                $pdfPath = $this->convertToPdf($media);
                $media->update(['converted_path' => $pdfPath]);
            }

            // Generate preview images
            $previews = $this->generatePreviews($media);
            
            // Extract text content
            $text = $this->extractText($media);
            
            // Generate thumbnails
            $thumbnails = $this->generateThumbnails($media);
            
            // Extract metadata
            $metadata = array_merge(
                $this->extractMetadata($media),
                [
                    'previews' => $previews,
                    'thumbnails' => $thumbnails,
                    'text_content' => $text
                ]
            );

            // Update media record
            $media->update([
                'metadata' => $metadata,
                'status' => Media::STATUS_COMPLETED
            ]);

            return $media;
        } catch (\Exception $e) {
            $media->update([
                'status' => Media::STATUS_FAILED,
                'metadata' => ['error' => $e->getMessage()]
            ]);
            throw $e;
        }
    }

    protected function convertToPdf(Media $media): string
    {
        $outputPath = $this->getPdfPath($media->path);
        
        $this->converter->convert(
            Storage::path($media->path),
            Storage::path($outputPath),
            $media->mime_type
        );

        return $outputPath;
    }

    protected function generatePreviews(Media $media): array
    {
        $previews = [];
        $pdfPath = $media->converted_path ?? $media->path;
        
        $pdf = new Pdf(Storage::path($pdfPath));
        $totalPages = $pdf->getNumberOfPages();

        // Generate preview for each page up to the limit
        for ($page = 1; $page <= min($totalPages, $this->config->maxPreviewPages); $page++) {
            $previewPath = $this->getPreviewPath($media->path, $page);
            
            $pdf->setPage($page)
                ->setResolution($this->config->previewResolution)
                ->saveImage(Storage::path($previewPath));
            
            $previews[] = [
                'path' => $previewPath,
                'page' => $page,
                'size' => Storage::size($previewPath)
            ];
        }

        return $previews;
    }

    protected function extractText(Media $media): string
    {
        $pdfPath = $media->converted_path ?? $media->path;
        return $this->converter->extractText(Storage::path($pdfPath));
    }

    protected function generateThumbnails(Media $media): array
    {
        $thumbnails = [];
        $pdfPath = $media->converted_path ?? $media->path;
        
        $pdf = new Pdf(Storage::path($pdfPath));
        
        // Generate thumbnail from first page
        $thumbnailPath = $this->getThumbnailPath($media->path);
        
        $pdf->setPage(1)
            ->setResolution($this->config->thumbnailResolution)
            ->saveImage(Storage::path($thumbnailPath));
        
        $thumbnails['default'] = [
            'path' => $thumbnailPath,
            'size' => Storage::size($thumbnailPath)
        ];

        return $thumbnails;
    }

    protected function extractMetadata(Media $media): array
    {
        $pdfPath = $media->converted_path ?? $media->path;
        $pdf = new Pdf(Storage::path($pdfPath));

        return [
            'pages' => $pdf->getNumberOfPages(),
            'size' => Storage::size($pdfPath),
            'created_at' => filectime(Storage::path($pdfPath)),
            'modified_at' => filemtime(Storage::path($pdfPath)),
            'processed_at' => now()
        ];
    }

    protected function getPdfPath(string $originalPath): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_converted.pdf';
    }

    protected function getPreviewPath(string $originalPath, int $page): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_preview_' . 
               $page . '.jpg';
    }

    protected function getThumbnailPath(string $originalPath): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_thumb.jpg';
    }
}

namespace App\Core\Media\Services;

class DocumentConversionService
{
    private $libreOffice;
    private $pdfTools;

    public function convert(string $inputPath, string $outputPath, string $mimeType): void
    {
        switch ($mimeType) {
            case 'application/msword':
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                $this->convertWordToPdf($inputPath, $outputPath);
                break;
                
            case 'application/vnd.ms-excel':
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                $this->convertExcelToPdf($inputPath, $outputPath);
                break;
                
            case 'application/vnd.ms-powerpoint':
            case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
                $this->convertPowerPointToPdf($inputPath, $outputPath);
                break;
                
            default:
                throw new UnsupportedFormatException("Unsupported format: {$mimeType}");
        }
    }

    protected function convertWordToPdf(string $input, string $output): void
    {
        $this->libreOffice->convert($input, $output, 'writer_pdf_Export');
    }

    protected function convertExcelToPdf(string $input, string $output): void
    {
        $this->libreOffice->convert($input, $output, 'calc_pdf_Export');
    }

    protected function convertPowerPointToPdf(string $input, string $output): void
    {
        $this->libreOffice->convert($input, $output, 'impress_pdf_Export');
    }

    public function extractText(string $pdfPath): string
    {
        return $this->pdfTools->extractText($pdfPath);
    }
}

namespace App\Core\Media\Config;

class DocumentConfig
{
    public int $maxPreviewPages = 10;
    public int $previewResolution = 150;
    public int $thumbnailResolution = 100;
    
    public array $conversionSettings = [
        'pdf' => [
            'compatibility' => '1.4',
            'compressed' => true
        ],
        'image' => [
            'format' => 'jpg',
            'quality' => 80
        ]
    ];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

namespace App\Core\Media\Jobs;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Media $media;
    public int $timeout = 3600;
    public int $tries = 3;

    public function __construct(Media $media)
    {
        $this->media = $media;
    }

    public function handle(DocumentProcessor $processor): void
    {
        try {
            $this->media->update(['status' => Media::STATUS_PROCESSING]);
            
            $processor->process($this->media);
            
        } catch (\Exception $e) {
            $this->media->update([
                'status' => Media::STATUS_FAILED,
                'metadata' => array_merge(
                    $this->media->metadata ?? [],
                    ['error' => $e->getMessage()]
                )
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Document processing failed', [
            'media_id' => $this->media->id,
            'error' => $exception->getMessage()
        ]);
    }
}
