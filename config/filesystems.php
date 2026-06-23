<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'served_public_storage_root' => env('SERVED_PUBLIC_STORAGE_ROOT'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('PUBLIC_STORAGE_URL', 'https://gpswiss.pl/storage'),
            'visibility' => 'public',
            'throw' => false,
        ],
    ],
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
