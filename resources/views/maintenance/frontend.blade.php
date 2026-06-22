<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Trwa przerwa techniczna | GPSwiss</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; color: #172033; background: radial-gradient(circle at top, #eef4ff 0, #f8fafc 42%, #eef2f7 100%); }
        main { width: min(100%, 680px); padding: 48px clamp(24px, 6vw, 56px); border: 1px solid rgba(15, 23, 42, .08); border-radius: 28px; background: rgba(255, 255, 255, .94); box-shadow: 0 24px 70px rgba(15, 23, 42, .12); text-align: center; }
        .badge { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 24px; padding: 8px 14px; border-radius: 999px; color: #1d4ed8; background: #dbeafe; font-size: 14px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
        .dot { width: 9px; height: 9px; border-radius: 999px; background: #2563eb; box-shadow: 0 0 0 6px rgba(37, 99, 235, .14); }
        h1 { margin: 0; font-size: clamp(32px, 6vw, 48px); line-height: 1.05; letter-spacing: -.04em; }
        p { margin: 20px auto 0; max-width: 520px; color: #475569; font-size: 18px; line-height: 1.65; }
        .contact { margin-top: 32px; display: grid; gap: 12px; }
        .contact a { display: inline-flex; justify-content: center; align-items: center; gap: 10px; color: #0f172a; font-size: 18px; font-weight: 700; text-decoration: none; }
        .contact a:hover { color: #1d4ed8; }
        footer { margin-top: 34px; color: #64748b; font-size: 14px; }
    </style>
</head>
<body>
<main aria-labelledby="maintenance-title">
    <div class="badge"><span class="dot" aria-hidden="true"></span> GPSwiss</div>
    <h1 id="maintenance-title">Trwa przerwa techniczna</h1>
    <p>{{ $message ?: 'Aktualizujemy sklep GPSwiss. Wrócimy najszybciej jak to możliwe.' }}</p>
    <p>Jeśli masz pytania, zapraszamy do kontaktu:</p>
    <div class="contact" aria-label="Kontakt">
        <a href="tel:+48504266984">📞 +48 504 266 984</a>
        <a href="mailto:biuro@gpswiss.pl">✉️ biuro@gpswiss.pl</a>
    </div>
    <footer>Przepraszamy za niedogodności.</footer>
</main>
</body>
</html>
