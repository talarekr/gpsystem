<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>eBay listing status sync diagnose</title></head>
<body>
<h1>eBay listing status sync diagnose</h1>
<p>Read-only: endpoint nie uruchamia runnera, nie wywołuje eBay API, nie zapisuje cache ani bazy.</p>
<pre>{{ json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</body>
</html>
