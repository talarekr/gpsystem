<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle ?? 'Dostęp do sklepu - GPSwiss' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #0B1F3A; --gold: #f4b400; --muted: #64748b; --line: #e2e8f0; --bg: #f8fafc; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Poppins, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--navy); background: radial-gradient(circle at top left, rgba(244, 180, 0, .18), transparent 32rem), linear-gradient(135deg, #fff 0%, var(--bg) 100%); padding: 24px; }
        .access-card { width: min(100%, 440px); background: #fff; border: 1px solid var(--line); border-radius: 28px; box-shadow: 0 24px 70px rgba(11, 31, 58, .14); padding: clamp(28px, 7vw, 42px); }
        .brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 24px; font-size: 24px; font-weight: 800; letter-spacing: -.04em; }
        .brand-mark { display: inline-grid; place-items: center; width: 46px; height: 46px; border-radius: 16px; background: var(--navy); color: var(--gold); }
        h1 { margin: 0 0 10px; font-size: clamp(28px, 7vw, 38px); line-height: 1.05; letter-spacing: -.05em; }
        p { margin: 0 0 24px; color: var(--muted); line-height: 1.6; }
        label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        input { width: 100%; border: 1px solid var(--line); border-radius: 16px; padding: 15px 16px; font: inherit; color: var(--navy); outline: none; transition: border-color .2s, box-shadow .2s; }
        input:focus { border-color: var(--gold); box-shadow: 0 0 0 4px rgba(244, 180, 0, .16); }
        button { width: 100%; margin-top: 16px; border: 0; border-radius: 16px; background: var(--navy); color: #fff; font: inherit; font-weight: 800; padding: 15px 18px; cursor: pointer; transition: transform .2s, box-shadow .2s; box-shadow: 0 12px 24px rgba(11, 31, 58, .16); }
        button:hover { transform: translateY(-1px); box-shadow: 0 16px 30px rgba(11, 31, 58, .22); }
        .error { margin: 0 0 16px; border: 1px solid #fecaca; border-radius: 14px; background: #fef2f2; color: #b91c1c; padding: 12px 14px; font-weight: 600; }
    </style>
</head>
<body>
    <main class="access-card" aria-labelledby="access-title">
        <div class="brand"><span class="brand-mark">GP</span><span>Swiss</span></div>
        <h1 id="access-title">Storefront staging</h1>
        <p>Podaj hasło, aby przejść do publicznej części sklepu.</p>

        @if ($errors->has('password'))
            <div class="error" role="alert">{{ $errors->first('password') }}</div>
        @endif

        <form method="post" action="{{ route('storefront.access.unlock') }}">
            @csrf
            <label for="password">Hasło dostępu</label>
            <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
            <button type="submit">Odblokuj storefront</button>
        </form>
    </main>
</body>
</html>
