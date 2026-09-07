<?php

return [
    'path' => env('FILAMENT_PATH', 'admin'),
    'domain' => env('FILAMENT_DOMAIN'),
    'home_url' => env('FILAMENT_HOME_URL', '/'),
    'brand' => env('FILAMENT_BRAND_NAME', 'Filament'),
    'auth' => [
        'guard' => env('FILAMENT_AUTH_GUARD', 'web'),
        'pages' => [
            'login' => \Filament\Pages\Auth\Login::class,
        ],
    ],
    'pages' => [
        'namespace' => 'App\\Filament\\Pages',
        'path' => app_path('Filament/Pages'),
        'register' => [],
    ],
    'resources' => [
        'namespace' => 'App\\Filament\\Resources',
        'path' => app_path('Filament/Resources'),
        'register' => [
            \App\Filament\Resources\CityResource::class,
        ],
    ],
    'widgets' => [
        'namespace' => 'App\\Filament\\Widgets',
        'path' => app_path('Filament/Widgets'),
        'register' => [],
    ],
    'livewire' => [
        'namespace' => 'App\\Filament',
    ],
    'dark_mode' => [
        'enabled' => true,
    ],
    'database_notifications' => [
        'enabled' => false,
    ],
    'broadcasting' => [
        'enabled' => false,
    ],
    'layout' => [
        'forms' => [
            'actions' => [
                'alignment' => 'left',
            ],
        ],
        'footer' => [
            'should_show_logo' => true,
        ],
        'max_content_width' => null,
        'notifications' => [
            'vertical_alignment' => 'top',
            'alignment' => 'right',
        ],
        'sidebar' => [
            'is_collapsible_on_desktop' => true,
            'groups' => [],
            'width' => null,
            'collapsed_width' => null,
        ],
    ],
];
