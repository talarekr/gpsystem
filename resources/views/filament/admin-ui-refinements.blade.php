@php
    $gpsAdminStylesheet = 'css/filament-admin.css';
    $gpsAdminStylesheetPath = public_path($gpsAdminStylesheet);
@endphp

<link
    rel="stylesheet"
    href="{{ asset($gpsAdminStylesheet) }}@if (file_exists($gpsAdminStylesheetPath))?v={{ filemtime($gpsAdminStylesheetPath) }}@endif"
>

@php
    $gpsAdminScript = 'js/filament-admin-topbar.js';
    $gpsAdminScriptPath = public_path($gpsAdminScript);
@endphp

<script
    src="{{ asset($gpsAdminScript) }}@if (file_exists($gpsAdminScriptPath))?v={{ filemtime($gpsAdminScriptPath) }}@endif"
    defer
></script>
