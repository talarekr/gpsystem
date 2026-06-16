@php
    $gpsAdminStylesheet = 'css/filament-admin.css';
    $gpsAdminStylesheetPath = public_path($gpsAdminStylesheet);
@endphp

<link
    rel="stylesheet"
    href="{{ asset($gpsAdminStylesheet) }}@if (file_exists($gpsAdminStylesheetPath))?v={{ filemtime($gpsAdminStylesheetPath) }}@endif"
>
