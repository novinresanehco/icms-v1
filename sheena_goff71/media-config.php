<?php

return [
    'default_disk' => env('MEDIA_DISK', 'public'),
    
    'disks' => [
        'public',
        's3',
        'minio'
    ],
    
    'max_upload_size' => env('MEDIA_MAX_UPLOAD_SIZE', 10240),
    
    'allowed_mimes' => 'jpeg,jpg,png,gif,webp,pdf,doc,docx,xls,xlsx,zip,mp4,webm',
    
    'image_sizes' => [
        'thumbnail' => [
            'width' => 150,
            'height' => 150
        ],
        'medium' => [
            'width' => 800,
            'height' => 600
        ],
        'large' => [
            'width' => 1920,
            'height' => 1080
        ]
    ],
    
    'video_thumbnails' => [
        'positions' => [0, 25, 50, 75],
        'size' => [
            'width' => 640,
            'height' => 360
        ]
    ],
    
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    
    'variant_types' => [
        'thumbnail',
        'medium',
        'large',
        'video_thumb'
    ],
    
    'cache_ttl' => env('MEDIA_CACHE_TTL', 3600),
    
    'cleanup' => [
        'unused_days' => env('MEDIA_CLEANUP_UNUSED_DAYS', 30),
        'chunk_size' => env('MEDIA_CLEANUP_CHUNK_SIZE', 100)
    ]
];
