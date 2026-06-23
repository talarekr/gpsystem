<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Warsztat — dodaj część</title>
    <style>
        :root { color-scheme: light; --blue:#155eef; --bg:#f4f7fb; --text:#111827; --muted:#6b7280; --danger:#b42318; --border:#d0d5dd; --ok:#12b76a; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size:18px; }
        .wrap { width:100%; max-width:560px; margin:0 auto; min-height:100vh; padding:18px 14px 128px; }
        .card { background:#fff; border-radius:22px; padding:20px; box-shadow:0 10px 28px rgba(16,24,40,.08); }
        h1 { font-size:24px; margin:0 0 8px; } h2 { font-size:21px; margin:0 0 14px; } p { line-height:1.45; }
        .section { padding:18px 0; border-top:1px solid #eaecf0; }
        .section:first-of-type { border-top:0; }
        label { display:block; font-weight:800; margin-bottom:10px; }
        input[type=text], textarea { width:100%; min-height:56px; border:2px solid var(--border); border-radius:16px; padding:14px 16px; font:inherit; background:#fff; }
        textarea { min-height:150px; resize:vertical; }
        .file-actions { display:grid; grid-template-columns:1fr; gap:12px; }
        .file-btn, button, .btn { min-height:56px; border:0; border-radius:16px; padding:14px 18px; font:inherit; font-weight:800; text-align:center; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
        .primary { background:var(--blue); color:#fff; } .secondary { background:#eef4ff; color:#1849a9; } .ghost { background:#fff; color:#344054; border:2px solid var(--border); }
        .file-btn { width:100%; min-height:68px; background:var(--blue); color:#fff; }
        .file-btn.secondary { background:#eef4ff; color:#1849a9; }
        input[type=file] { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; }
        .thumbs { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-top:16px; }
        .thumb { position:relative; aspect-ratio:1; border-radius:18px; overflow:hidden; background:#eaecf0; border:1px solid #d0d5dd; }
        .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .thumb button { position:absolute; top:8px; right:8px; min-height:46px; min-width:46px; padding:0; border-radius:999px; background:rgba(180,35,24,.95); color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.22); }
        .errors { background:#fef3f2; color:var(--danger); border:1px solid #fecdca; border-radius:16px; padding:12px 14px; margin:0 0 16px; font-weight:700; }
        .hint { color:var(--muted); font-size:15px; margin-top:8px; }
        .upload-message { margin:12px 0 0; padding:12px 14px; border-radius:16px; background:#eef4ff; color:#1849a9; font-weight:800; }
        .actions { position:fixed; left:0; right:0; bottom:0; background:rgba(255,255,255,.96); border-top:1px solid var(--border); padding:12px max(14px, env(safe-area-inset-left)) max(12px, env(safe-area-inset-bottom)) max(14px, env(safe-area-inset-right)); display:flex; gap:10px; justify-content:center; }
        .actions-inner { width:100%; max-width:560px; display:flex; gap:10px; } .actions-inner > * { flex:1; }
        .success { border-left:6px solid var(--ok); }
        .success-actions { display:grid; gap:12px; }
        .hidden { display:none !important; }
        .spinner { width:20px; height:20px; border:3px solid rgba(255,255,255,.45); border-top-color:#fff; border-radius:999px; animation:spin .8s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
        @media (min-width:460px) { .file-actions { grid-template-columns:1fr 1fr; } .thumbs { grid-template-columns:repeat(3,1fr); } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card {{ $part ? 'success' : '' }}">
        @if($part)
            <h1>Część dodana</h1>
            <p><strong>Część dodana do: Części -&gt; Do wystawienia</strong></p>
            <p>Kod części: {{ $part['part_number'] }}</p>
            <div class="success-actions">
                <a class="btn primary" href="{{ url('/tools/workshop/quick-part-create?token='.$token) }}">Dodaj kolejną część</a>
                <a class="btn secondary" href="{{ $part['admin_url'] }}">Otwórz część</a>
            </div>
        @else
            <h1>Warsztat — dodaj część</h1>
            <p class="hint">Szybki intake lokalny. Część trafi do kolejki „Do wystawienia”.</p>
            @if($errors->any())
                <div class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form id="quickPartForm" method="post" action="{{ route('tools.workshop.quick-part-create.store', ['token' => $token]) }}" enctype="multipart/form-data">
                @csrf
                <section class="section" aria-labelledby="photosHeading">
                    <h2 id="photosHeading">1. Zdjęcia części</h2>
                    <div class="file-actions">
                        <label for="cameraPhotos" class="file-btn">📷 Zrób zdjęcie</label>
                        <label for="galleryPhotos" class="file-btn secondary">🖼️ Wybierz z telefonu</label>
                    </div>
                    <input id="cameraPhotos" type="file" accept="image/*" capture="environment" multiple>
                    <input id="galleryPhotos" type="file" accept="image/*" multiple>
                    <input id="photos" name="photos[]" type="file" accept="image/*" multiple required>
                    <div id="thumbs" class="thumbs" aria-live="polite"></div>
                    <p class="hint">Wymagane minimum 1 zdjęcie. Możesz mieszać zdjęcia z aparatu i galerii oraz usuwać miniatury przed zapisem.</p>
                </section>
                <section class="section">
                    <h2>2. Magazyn / miejsce składowania</h2>
                    <label for="storage_location">Magazyn / miejsce składowania</label>
                    <input id="storage_location" name="storage_location" type="text" value="{{ old('storage_location') }}" placeholder="np. A1-P2, Regał 3 / Półka 4, Hala B / Kosz 12" required>
                </section>
                <section class="section">
                    <h2>3. Główny kod części</h2>
                    <label for="part_number">Główny kod części</label>
                    <input id="part_number" name="part_number" type="text" value="{{ old('part_number') }}" placeholder="np. 8K0953568D" required>
                </section>
                <section class="section">
                    <h2>4. Notatka wewnętrzna</h2>
                    <label for="internal_note">Notatka wewnętrzna <span class="hint">(opcjonalna)</span></label>
                    <textarea id="internal_note" name="internal_note" placeholder="Opcjonalnie: stan części, cena orientacyjna, uwagi dla obsługi sklepu">{{ old('internal_note') }}</textarea>
                </section>
                <p id="uploadMessage" class="upload-message hidden">Trwa przesyłanie zdjęć, nie zamykaj strony</p>
                <div class="actions"><div class="actions-inner">
                    <button type="submit" id="saveBtn" class="primary"><span id="saveSpinner" class="spinner hidden" aria-hidden="true"></span><span id="saveText">Zapisz część</span></button>
                </div></div>
            </form>
        @endif
    </div>
</div>
<script>
(() => {
    const cameraInput = document.getElementById('cameraPhotos');
    const galleryInput = document.getElementById('galleryPhotos');
    const photosInput = document.getElementById('photos');
    const thumbs = document.getElementById('thumbs');
    const form = document.getElementById('quickPartForm');
    const saveBtn = document.getElementById('saveBtn');
    const saveText = document.getElementById('saveText');
    const saveSpinner = document.getElementById('saveSpinner');
    const uploadMessage = document.getElementById('uploadMessage');
    const files = new DataTransfer();
    const objectUrls = [];

    const syncInput = () => { if (photosInput) photosInput.files = files.files; };
    const revokeObjectUrls = () => { while (objectUrls.length) URL.revokeObjectURL(objectUrls.pop()); };
    const renderThumbs = () => {
        if (!thumbs) return;
        revokeObjectUrls();
        thumbs.innerHTML = '';
        Array.from(files.files).forEach((file, index) => {
            const box = document.createElement('div'); box.className = 'thumb';
            const img = document.createElement('img'); const url = URL.createObjectURL(file); objectUrls.push(url); img.src = url; img.alt = file.name;
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.ariaLabel = 'Usuń zdjęcie';
            remove.onclick = () => { const next = new DataTransfer(); Array.from(files.files).forEach((f, i) => { if (i !== index) next.items.add(f); }); files.items.clear(); Array.from(next.files).forEach(f => files.items.add(f)); syncInput(); renderThumbs(); };
            box.append(img, remove); thumbs.append(box);
        });
    };
    const addFiles = input => {
        Array.from(input.files || []).forEach(file => files.items.add(file));
        input.value = '';
        syncInput();
        renderThumbs();
    };
    const compressImage = file => new Promise(resolve => {
        if (!file.type.startsWith('image/')) return resolve(file);
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            const maxSide = 1800;
            const scale = Math.min(1, maxSide / Math.max(img.width, img.height));
            if (scale >= 1 && file.size <= 1800 * 1024) return resolve(file);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale); canvas.height = Math.round(img.height * scale);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(blob => resolve(blob ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }) : file), 'image/jpeg', 0.82);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
    const setSaving = () => {
        if (!saveBtn) return;
        saveBtn.disabled = true; saveText.textContent = 'Zapisywanie...'; saveSpinner.classList.remove('hidden'); uploadMessage.classList.remove('hidden');
    };
    cameraInput?.addEventListener('change', () => addFiles(cameraInput));
    galleryInput?.addEventListener('change', () => addFiles(galleryInput));
    form?.addEventListener('submit', async event => {
        if (files.files.length < 1) { event.preventDefault(); alert('Dodaj minimum jedno zdjęcie części.'); return; }
        if (!form.checkValidity()) return;
        event.preventDefault(); setSaving();
        const compressed = new DataTransfer();
        for (const file of Array.from(files.files)) compressed.items.add(await compressImage(file));
        files.items.clear(); Array.from(compressed.files).forEach(file => files.items.add(file)); syncInput();
        form.submit();
    });
})();
</script>
</body>
</html>
