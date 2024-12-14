<?php

namespace App\Core\Media\Processors;

use App\Core\Media\Contracts\MediaProcessorInterface;
use App\Core\Media\Models\Media;
use App\Core\Media\Config\VideoConfig;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;

class VideoProcessor implements MediaProcessorInterface
{
    protected VideoConfig $config;
    protected FFMpeg $ffmpeg;
    
    protected array $allowedMimeTypes = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-flv'
    ];

    public function __construct(VideoConfig $config)
    {
        $this->config = $config;
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('media.ffmpeg_path'),
            'ffprobe.binaries' => config('media.ffprobe_path'),
            'timeout' => 3600,
            'ffmpeg.threads' => 12,
        ]);
    }

    public function supports(Media $media): bool
    {
        return in_array($media->mime_type, $this->allowedMimeTypes);
    }

    public function process(Media $media): Media
    {
        try {
            // Open video
            $video = $this->ffmpeg->open(Storage::path($media->path));
            
            // Process original video
            $this->optimizeOriginal($video, $media);
            
            // Generate thumbnails
            $thumbnails = $this->generateThumbnails($video, $media);
            
            // Generate previews
            $previews = $this->generatePreviews($video, $media);
            
            // Extract metadata
            $metadata = array_merge(
                $this->extractMetadata($video),
                [
                    'thumbnails' => $thumbnails,
                    'previews' => $previews
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

    protected function optimizeOriginal($video, Media $media): void
    {
        $format = new X264('aac', 'libx264');
        
        $format->setKiloBitrate($this->config->videoBitrate)
               ->setAudioKiloBitrate($this->config->audioBitrate)
               ->setAudioChannels($this->config->audioChannels);

        $optimizedPath = $this->getOptimizedPath($media->path);
        
        $video->save($format, Storage::path($optimizedPath));
        
        // Replace original with optimized version
        if (Storage::exists($optimizedPath)) {
            Storage::delete($media->path);
            $media->update(['path' => $optimizedPath]);
        }
    }

    protected function generateThumbnails($video, Media $media): array
    {
        $thumbnails = [];
        $duration = $video->getStreams()->first()->get('duration');
        
        // Generate thumbnails at different intervals
        foreach ($this->config->thumbnailIntervals as $interval) {
            $time = min($duration * $interval, $duration);
            $thumbnailPath = $this->getThumbnailPath($media->path, $time);
            
            $frame = $video->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds($time));
            $frame->save(Storage::path($thumbnailPath));
            
            $thumbnails[] = [
                'path' => $thumbnailPath,
                'time' => $time,
                'size' => Storage::size($thumbnailPath)
            ];
        }

        return $thumbnails;
    }

    protected function generatePreviews($video, Media $media): array
    {
        $previews = [];
        
        foreach ($this->config->previewFormats as $format => $settings) {
            $previewPath = $this->getPreviewPath($media->path, $format);
            
            $format = new X264('aac', 'libx264');
            $format->setKiloBitrate($settings['bitrate'])
                   ->setAudioKiloBitrate($settings['audioBitrate']);

            $video->clip(
                \FFMpeg\Coordinate\TimeCode::fromSeconds(0),
                \FFMpeg\Coordinate\TimeCode::fromSeconds($settings['duration'])
            );
            
            $video->save($format, Storage::path($previewPath));
            
            $previews[$format] = [
                'path' => $previewPath,
                'duration' => $settings['duration'],
                'size' => Storage::size($previewPath)
            ];
        }

        return $previews;
    }

    protected function extractMetadata($video): array
    {
        $stream = $video->getStreams()->first();
        
        return [
            'duration' => $stream->get('duration'),
            'dimensions' => [
                'width' => $stream->get('width'),
                'height' => $stream->get('height')
            ],
            'bitrate' => $stream->get('bit_rate'),
            'framerate' => $stream->get('r_frame_rate'),
            'codec' => $stream->get('codec_name'),
            'processed_at' => now()
        ];
    }

    protected function getOptimizedPath(string $originalPath): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_optimized.' . 
               'mp4';
    }

    protected function getThumbnailPath(string $originalPath, float $time): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_thumb_' . 
               number_format($time, 2) . '.jpg';
    }

    protected function getPreviewPath(string $originalPath, string $format): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_preview_' . 
               $format . '.mp4';
    }
}

namespace App\Core\Media\Config;

class VideoConfig
{
    public int $videoBitrate = 1000;
    public int $audioBitrate = 128;
    public int $audioChannels = 2;
    
    public array $thumbnailIntervals = [
        0.1, // Start
        0.25, // Quarter
        0.5,  // Middle
        0.75  // Three quarters
    ];
    
    public array $previewFormats = [
        'low' => [
            'bitrate' => 500,
            'audioBitrate' => 64,
            'duration' => 10
        ],
        'medium' => [
            'bitrate' => 1000,
            'audioBitrate' => 128,
            'duration' => 15
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

namespace App\Core\Media\Services;

class VideoTransformationService
{
    protected FFMpeg $ffmpeg;
    
    public function trim(Media $media, float $start, float $duration): Media
    {
        $video = $this->ffmpeg->open(Storage::path($media->path));
        
        $video->clip(
            \FFMpeg\Coordinate\TimeCode::fromSeconds($start),
            \FFMpeg\Coordinate\TimeCode::fromSeconds($duration)
        );

        $newPath = $this->getTransformedPath($media->path, "trim_{$start}_{$duration}");
        
        $format = new X264('aac', 'libx264');
        $video->save($format, Storage::path($newPath));
        
        return $this->createTransformedMedia($media, $newPath, [
            'transformation' => 'trim',
            'params' => compact('start', 'duration')
        ]);
    }

    public function resize(Media $media, int $width, int $height): Media
    {
        $video = $this->ffmpeg->open(Storage::path($media->path));
        
        $video->filters()->resize(new \FFMpeg\Coordinate\Dimension($width, $height));
        
        $newPath = $this->getTransformedPath($media->path, "resize_{$width}x{$height}");
        
        $format = new X264('aac', 'libx264');
        $video->save($format, Storage::path($newPath));
        
        return $this->createTransformedMedia($media, $newPath, [
            'transformation' => 'resize',
            'params' => compact('width', 'height')
        ]);
    }

    protected function createTransformedMedia(Media $originalMedia, string $newPath, array $transformationData): Media
    {
        return Media::create([
            'filename' => basename($newPath),
            'mime_type' => 'video/mp4',
            'path' => $newPath,
            'size' => Storage::size($newPath),
            'metadata' => array_merge(
                $originalMedia->metadata ?? [],
                ['transformation' => $transformationData]
            ),
            'parent_id' => $originalMedia->id,
            'status' => Media::STATUS_COMPLETED
        ]);
    }

    protected function getTransformedPath(string $originalPath, string $suffix): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_' . 
               $suffix . '.mp4';
    }
}

