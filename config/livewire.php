<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    |
    | Livewire validates temporary uploads before Filament's FileUpload field
    | validation runs. Keep this limit aligned with the temporary Woo migration
    | import page, where products.csv and other large CSV exports can be up to
    | 100 MB. The value is expressed in kilobytes.
    |
    */
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => 'file|max:102400',
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
