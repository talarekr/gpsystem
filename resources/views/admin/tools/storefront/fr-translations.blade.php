<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>GPSwiss FR — tłumaczenia katalogu</title>
    <style>
        :root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f6f9;color:#18283f;font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{max-width:1240px;margin:auto;padding:28px 20px 48px}a{color:#285b94}h1{font-size:clamp(24px,4vw,32px);margin:14px 0 8px}h2{font-size:20px;margin:0 0 14px}h3{font-size:16px;margin:18px 0 10px}p{margin:8px 0 14px}.muted{color:#526278}.card{background:#fff;border:1px solid #dce3ec;border-radius:14px;padding:22px;margin-top:18px}.notice{background:#edf4ff;border-color:#bdcfe8}.warning{background:#fff8e7;border-color:#efd087}.row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.between{justify-content:space-between}.badge{font-size:13px;font-weight:700;padding:5px 10px;border-radius:20px;background:#e9edf2;display:inline-block}.good{background:#e2f3e8;color:#17603a}.bad{background:#fce7e7;color:#9c2929}.pending{background:#e7effd;color:#285b94}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.metric{background:#f7f9fc;border:1px solid #e4e9f1;border-radius:10px;padding:12px}.metric span{display:block;font-size:13px;color:#526278}.metric strong{display:block;font-size:23px;margin-top:4px;overflow-wrap:anywhere}.button{display:inline-block;border:1px solid transparent;border-radius:8px;padding:10px 15px;font:600 14px/1.4 system-ui;cursor:pointer;background:#e8edf4;color:#203650}.primary{background:#244d7f;color:#fff}.danger{background:#a82c32;color:#fff}button:disabled{opacity:.5;cursor:not-allowed}button:focus-visible,input:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid #739fe0;outline-offset:3px}input{font:inherit;width:min(100%,450px);border:1px solid #9babc0;border-radius:8px;padding:10px 12px;margin:8px 0 12px}label{display:block;font-weight:600}.mono,code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}code{font-size:.9em;background:#eef1f5;padding:2px 4px;border-radius:3px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid #e4e9f1;text-align:left;padding:9px 12px;vertical-align:top}th{color:#526278;font-weight:600}td:first-child,th:first-child{padding-left:0}details{margin-top:16px}summary{cursor:pointer;font-weight:600}pre{white-space:pre-wrap;overflow-wrap:anywhere;max-height:320px;overflow:auto;font:13px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;background:#f4f6fa;border-radius:8px;padding:14px}.progress{width:100%;height:18px;accent-color:#244d7f}.status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:16px}.status-grid dt{font-size:13px;color:#526278}.status-grid dd{margin:4px 0 0;font-weight:600;overflow-wrap:anywhere}.messages{min-height:24px;margin-top:16px}.messages.error{color:#a1252e}.messages.success{color:#17603a}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}[hidden]{display:none!important}@media(max-width:600px){main{padding:18px 12px 32px}.card{padding:16px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}input{font-size:13px}.row>.button{flex-grow:1}}
    </style>
</head>
<body>
<main>
    <a href="{{ url('/admin') }}">← Wróć do panelu admina</a>
    <div class="row between">
        <h1>GPSwiss FR — tłumaczenia katalogu</h1>
        <span class="badge">Uruchamianie ręczne</span>
    </div>
    <p class="muted">Sprawdź zakres tłumaczeń, a następnie uruchom zadanie i obserwuj postęp.</p>
    @php
        $catalogKinds = ['products' => 'Produkty', 'categories' => 'Kategorie'];
        $jarekKinds = ['jarek_gearboxes' => 'Skrzynie Jarka'];
    @endphp
    <section id="catalog-translations" aria-labelledby="catalog-section-title">
        <h2 id="catalog-section-title">Produkty i kategorie GPSwiss.fr</h2>
        @include('admin.tools.storefront._fr-translation-section', ['prefix' => '', 'kinds' => $catalogKinds, 'confirmToken' => 'TRANSLATE-ALL-GPSWISS-FR-CATALOG', 'startLabel' => 'Przetłumacz cały katalog', 'scopeDescription' => 'Zakres obejmuje produkty i kategorie'])
    </section>
    <section id="jarek-translations" aria-labelledby="jarek-section-title">
        <h2 id="jarek-section-title">Skrzynie Jarka — tłumaczenia FR</h2>
        <p>Ta sekcja tłumaczy wyłącznie treści Jarka. Nie publikuje produktów, nie tworzy Part, nie zmienia cen ani stocku i nie robi marketplace writes. Blokery publikacji FR nie blokują tłumaczenia tekstów.</p>
        <p>Zrób dry-run, wpisz <code>TRANSLATE-JAREK-GEARBOXES-FR</code> i kliknij Start. Zostaw stronę otwartą. Po przerwaniu wróć i kliknij Wznów dla istniejącego runu. Nie klikaj ponownie Start, gdy run jest aktywny. Publikacja na gpswiss.fr to osobny etap po tłumaczeniach.</p>
        @include('admin.tools.storefront._fr-translation-section', ['overview' => $jarekOverview, 'status' => $jarekStatus, 'prefix' => 'jarek-', 'kinds' => $jarekKinds, 'confirmToken' => 'TRANSLATE-JAREK-GEARBOXES-FR', 'startLabel' => 'Start tłumaczeń Jarka', 'scopeDescription' => 'Zakres obejmuje tylko tytuły i opisy skrzyń Jarka'])
    </section>
</main>
@include('admin.tools.storefront._fr-translation-runner')
<script>
@foreach (['catalog' => ['root' => 'catalog-translations', 'prefix' => '', 'kinds' => $catalogKinds, 'overview' => $overview, 'status' => $status, 'confirm' => 'TRANSLATE-ALL-GPSWISS-FR-CATALOG'], 'jarek' => ['root' => 'jarek-translations', 'prefix' => 'jarek-', 'kinds' => $jarekKinds, 'overview' => $jarekOverview, 'status' => $jarekStatus, 'confirm' => 'TRANSLATE-JAREK-GEARBOXES-FR']] as $section => $config)
    @php
        $routePrefix = 'admin.tools.storefront.fr-translations.'.($section === 'jarek' ? 'jarek.' : '');
        $urls = [];
        foreach (['status' => 'status', 'dryRun' => 'dry-run', 'start' => 'start', 'tick' => 'tick', 'pause' => 'pause', 'resume' => 'resume', 'stop' => 'stop'] as $action => $routeName) $urls[$action] = route($routePrefix.$routeName, [], false);
    @endphp
    mountFrTranslationSection(document.getElementById(@json($config['root'])), @json($config['overview']), @json($config['status']), @json($urls), @json($config['confirm']), @json($config['prefix']), @json(array_map(null, array_keys($config['kinds']), array_values($config['kinds']))));
@endforeach
</script>
</body>
</html>
