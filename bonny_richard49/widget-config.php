// config/widgets.php
<?php

return [
    'cache' => [
        'enabled' => env('WIDGET_CACHE_ENABLED', true),
        'ttl' => env('WIDGET_CACHE_TTL', 3600),
    ],

    'types' => [
        'content' => \App\Core\Widget\Types\ContentWidget::class,
        'menu' => \App\Core\Widget\Types\MenuWidget::class,
        'social' => \App\Core\Widget\Types\SocialWidget::class,
    ],

    'processors' => [
        'content' => \App\Core\Widget\Processors\ContentWidgetProcessor::class,
        'menu' => \App\Core\Widget\Processors\MenuWidgetProcessor::class,
        'social' => \App\Core\Widget\Processors\SocialWidgetProcessor::class,
    ],

    'renderers' => [
        'content' => \App\Core\Widget\Renderers\ContentWidgetRenderer::class,
        'menu' => \App\Core\Widget\Renderers\MenuWidgetRenderer::class,
        'social' => \App\Core\Widget\Renderers\SocialWidgetRenderer::class,
    ],

    'defaults' => [
        'content' => [
            'show_title' => true,
            'show_date' => true,
            'max_items' => 5,
        ],
        'menu' => [
            'depth' => 1,
            'show_description' => false,
        ],
        'social' => [
            'show_icons' => true,
            'target' => '_blank',
        ],
    ],

    'permissions' => [
        'manage' => 'widgets.manage',
        'create' => 'widgets.create',
        'edit' => 'widgets.edit',
        'delete' => 'widgets.delete',
    ],

    'views' => [
        'namespace' => 'widgets',
        'path' => resource_path('views/widgets'),
    ],
];