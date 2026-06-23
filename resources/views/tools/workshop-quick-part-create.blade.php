<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Warsztat — dodaj część</title>
    <style>
        :root { color-scheme: light; --blue:#155eef; --bg:#f4f7fb; --text:#111827; --muted:#6b7280; --danger:#b42318; --border:#d0d5dd; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size:18px; }
        .wrap { width:100%; max-width:560px; margin:0 auto; min-height:100vh; padding:18px 14px 112px; }
        .card { background:#fff; border-radius:22px; padding:20px; box-shadow:0 10px 28px rgba(16,24,40,.08); }
        h1 { font-size:24px; margin:0 0 8px; } h2 { font-size:22px; margin:0 0 16px; } p { line-height:1.45; }
        .step { display:none; } .step.active { display:block; }
        label { display:block; font-weight:800; margin-bottom:10px; }
        input[type=text], textarea { width:100%; min-height:56px; border:2px solid var(--border); border-radius:16px; padding:14px 16px; font:inherit; }
        textarea { min-height:168px; resize:vertical; }
        .file-btn, button, .btn { min-height:52px; border:0; border-radius:16px; padding:14px 18px; font:inherit; font-weight:800; text-align:center; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
        .primary { background:var(--blue); color:#fff; } .secondary { background:#eef4ff; color:#1849a9; } .ghost { background:#fff; color:#344054; border:2px solid var(--border); }
        .file-btn { width:100%; min-height:70px; background:var(--blue); color:#fff; }
        input[type=file] { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; }
        .thumbs { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:16px; }
        .thumb { position:relative; aspect-ratio:1; border-radius:14px; overflow:hidden; background:#eaecf0; }
        .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .thumb button { position:absolute; top:6px; right:6px; min-height:44px; min-width:44px; padding:0; border-radius:999px; background:rgba(180,35,24,.95); color:#fff; }
        .errors { background:#fef3f2; color:var(--danger); border:1px solid #fecdca; border-radius:16px; padding:12px 14px; margin:0 0 16px; font-weight:700; }
        .hint { color:var(--muted); font-size:15px; margin-top:8px; }
        .actions { position:fixed; left:0; right:0; bottom:0; background:rgba(255,255,255,.96); border-top:1px solid var(--border); padding:12px max(14px, env(safe-area-inset-left)) max(12px, env(safe-area-inset-bottom)) max(14px, env(safe-area-inset-right)); display:flex; gap:10px; justify-content:center; }
        .actions-inner { width:100%; max-width:560px; display:flex; gap:10px; } .actions-inner > * { flex:1; }
        .success { border-left:6px solid #12b76a; }
        .hidden { display:none !important; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card {{ $part ? 'success' : '' }}">
        @if($part)
            <h1>Część dodana</h1>
            <p><strong>Część dodana do: Części -&gt; Do wystawienia</strong></p>
            <p>Kod części: {{ $part['part_number'] }}</p>
            <p><a class="btn primary" href="{{ url('/tools/workshop/quick-part-create?token='.$token) }}">Dodaj kolejną część</a></p>
            <p><a class="btn secondary" href="{{ $part['admin_url'] }}">Otwórz część</a></p>
        @else
            <h1>Warsztat — dodaj część</h1>
            <p class="hint">Szybki intake lokalny. Część trafi do kolejki „Do wystawienia”.</p>
            @if($errors->any())
                <div class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form id="quickPartForm" method="post" action="{{ route('tools.workshop.quick-part-create.store', ['token' => $token]) }}" enctype="multipart/form-data">
                @csrf
                <section class="step active" data-step="1">
                    <h2>Krok 1/4: Zdjęcia</h2>
                    <label for="photos" class="file-btn">📷 Zrób zdjęcia części</label>
                    <input id="photos" name="photos[]" type="file" accept="image/*" capture="environment" multiple required>
                    <div id="thumbs" class="thumbs"></div>
                    <p class="hint">Wymagane minimum 1 zdjęcie. Możesz zaznaczyć kilka zdjęć.</p>
                </section>
                <section class="step" data-step="2">
                    <h2>Krok 2/4: Magazyn</h2>
                    <label for="storage_location">Miejsce magazynowania</label>
                    <input id="storage_location" name="storage_location" type="text" value="{{ old('storage_location') }}" placeholder="np. A1-P2, Regał 3 / Półka 4, Hala B / Kosz 12" required>
                </section>
                <section class="step" data-step="3">
                    <h2>Krok 3/4: Kod części</h2>
                    <label for="part_number">Główny kod części</label>
                    <input id="part_number" name="part_number" type="text" value="{{ old('part_number') }}" placeholder="np. 8K0953568D" required>
                </section>
                <section class="step" data-step="4">
                    <h2>Krok 4/4: Notatka wewnętrzna</h2>
                    <label for="internal_note">Notatka wewnętrzna</label>
                    <textarea id="internal_note" name="internal_note" placeholder="Opcjonalnie: stan części, cena orientacyjna, uwagi dla obsługi sklepu">{{ old('internal_note') }}</textarea>
                </section>
                <div class="actions"><div class="actions-inner">
                    <button type="button" id="backBtn" class="ghost hidden">Wstecz</button>
                    <button type="button" id="nextBtn" class="primary">Dalej</button>
                    <button type="submit" id="saveBtn" class="primary hidden">Zapisz część</button>
                </div></div>
            </form>
        @endif
    </div>
</div>
<script>
(() => {
    let step = 1;
    const fileInput = document.getElementById('photos');
    const thumbs = document.getElementById('thumbs');
    const nextBtn = document.getElementById('nextBtn');
    const backBtn = document.getElementById('backBtn');
    const saveBtn = document.getElementById('saveBtn');
    const files = new DataTransfer();
    const show = () => {
        document.querySelectorAll('.step').forEach(el => el.classList.toggle('active', Number(el.dataset.step) === step));
        backBtn.classList.toggle('hidden', step === 1);
        nextBtn.classList.toggle('hidden', step === 4);
        saveBtn.classList.toggle('hidden', step !== 4);
    };
    const renderThumbs = () => {
        thumbs.innerHTML = '';
        Array.from(files.files).forEach((file, index) => {
            const box = document.createElement('div'); box.className = 'thumb';
            const img = document.createElement('img'); img.src = URL.createObjectURL(file); img.alt = file.name;
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.ariaLabel = 'Usuń zdjęcie';
            remove.onclick = () => { const next = new DataTransfer(); Array.from(files.files).forEach((f, i) => { if (i !== index) next.items.add(f); }); files.items.clear(); Array.from(next.files).forEach(f => files.items.add(f)); fileInput.files = files.files; renderThumbs(); };
            box.append(img, remove); thumbs.append(box);
        });
    };
    fileInput?.addEventListener('change', () => { Array.from(fileInput.files).forEach(f => files.items.add(f)); fileInput.files = files.files; renderThumbs(); });
    nextBtn?.addEventListener('click', () => { if (step === 1 && files.files.length < 1) return alert('Dodaj minimum jedno zdjęcie części.'); const field = document.querySelector(`.step[data-step="${step}"] [required]`); if (field && !field.reportValidity()) return; step = Math.min(4, step + 1); show(); });
    backBtn?.addEventListener('click', () => { step = Math.max(1, step - 1); show(); });
})();
</script>
</body>
</html>
