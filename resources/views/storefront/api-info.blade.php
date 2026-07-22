<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('storefront.api_integration') }}</title>
</head>
<body>
    <main style="max-width: 760px; margin: 3rem auto; font-family: system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; line-height: 1.55; padding: 0 1rem; color: #111827;">
        <h1>{{ __('storefront.api_integration') }}</h1>

        <p>{{ __('storefront.api_info_intro') }}</p>

        <h2>{{ __('storefront.app_name') }}</h2>
        <p>GPswiss</p>

        <h2>{{ __('storefront.app_version') }}</h2>
        <p>v1.0</p>

        <h2>User-Agent:</h2>
        <p><strong>GPswiss/v1.0 (+https://gpswiss.pl/api-info)</strong></p>

        <h2>{{ __('storefront.integration_goal') }}</h2>
        <p>{{ __('storefront.api_info_scope') }}</p>
        <ul>
            <li>{{ __('storefront.api_info_orders') }}</li>
            <li>{{ __('storefront.api_info_statuses') }}</li>
            <li>{{ __('storefront.api_info_listings') }}</li>
            <li>{{ __('storefront.api_info_manage') }}</li>
            <li>{{ __('storefront.api_info_stock') }}</li>
        </ul>

        <h2>{{ __('storefront.access') }}</h2>
        <p>{{ __('storefront.api_info_access') }}</p>

        <h2>{{ __('storefront.contact') }}:</h2>
        <p><a href="mailto:gregor1142@gmail.com">gregor1142@gmail.com</a></p>
    </main>
</body>
</html>
