<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Warsztat — dodaj część</title>
    <style>
        :root { color-scheme: light; --blue:#155eef; --bg:#f4f7fb; --text:#111827; --muted:#6b7280; --danger:#b42318; --border:#d0d5dd; --ok:#12b76a; --overlay:rgba(16,24,40,.86); }
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
        .scan-status { margin:10px 0 0; padding:10px 12px; border-radius:14px; background:#ecfdf3; color:#027a48; font-weight:800; }
        .scan-status.error { background:#fef3f2; color:var(--danger); }
        .ocr-modal { position:fixed; inset:0; z-index:20; background:#000; color:#fff; display:grid; grid-template-rows:auto 1fr auto; }
        .ocr-modal video { width:100%; height:100%; object-fit:cover; }
        .ocr-header, .ocr-footer { position:relative; z-index:3; padding:16px; background:rgba(0,0,0,.62); text-align:center; }
        .ocr-header { display:grid; gap:12px; }
        .ocr-mode-title { font-size:15px; font-weight:900; letter-spacing:.02em; text-transform:uppercase; }
        .ocr-mode-toggle { display:grid; grid-template-columns:1fr 1fr; gap:8px; width:min(100%, 360px); margin:0 auto; padding:4px; border:2px solid rgba(255,255,255,.76); border-radius:18px; background:rgba(255,255,255,.12); }
        .ocr-mode-toggle button { min-height:48px; border-radius:13px; padding:10px 12px; background:transparent; color:#fff; border:0; font-size:17px; }
        .ocr-mode-toggle button[aria-pressed="true"] { background:#fff; color:#1849a9; box-shadow:0 6px 18px rgba(0,0,0,.28); }
        .ocr-frame { position:absolute; left:50%; top:46%; width:var(--ocr-frame-width, 92vw); max-width:620px; height:var(--ocr-frame-height, 60px); transform:translate(-50%,-50%); border:3px solid #fff; border-radius:12px; box-shadow:0 0 0 9999px var(--overlay); z-index:2; transition:width .18s ease, height .18s ease; }
        .ocr-tip { position:absolute; left:50%; top:calc(46% + var(--ocr-tip-offset, 46px)); transform:translateX(-50%); width:min(92vw, 620px); z-index:3; margin:0; padding:10px 12px; border-radius:14px; background:rgba(0,0,0,.68); color:#fff; font-size:15px; font-weight:800; text-align:center; }
        .ocr-focus-message { position:absolute; left:50%; top:calc(46% - 86px); transform:translateX(-50%); z-index:4; padding:10px 14px; border-radius:999px; background:rgba(21,94,239,.92); color:#fff; font-weight:900; box-shadow:0 8px 20px rgba(0,0,0,.25); }
        .ocr-footer { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .ocr-footer button { width:100%; }
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
                <a class="btn primary" href="{{ $createAnotherUrl }}">Dodaj kolejną część</a>
                <a class="btn secondary" href="{{ $part['admin_url'] }}">Otwórz część</a>
            </div>
        @else
            <h1>Warsztat — dodaj część</h1>
            <p class="hint">Szybki intake lokalny. Część trafi do kolejki „Do wystawienia”.</p>
            @if($errors->any())
                <div class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form id="quickPartForm" method="post" action="{{ $formAction }}" enctype="multipart/form-data">
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
                    <button type="button" id="scanPartNumberBtn" class="secondary">Skanuj kod z etykiety</button>
                    <p id="scanStatus" class="scan-status hidden" aria-live="polite"></p>
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
<div id="ocrModal" class="ocr-modal hidden" role="dialog" aria-modal="true" aria-labelledby="ocrTitle">
    <div class="ocr-header">
        <strong id="ocrTitle">Ustaw tylko jeden wiersz kodu części w ramce</strong>
        <div class="ocr-mode-title">Rozmiar etykiety</div>
        <div class="ocr-mode-toggle" role="group" aria-label="Rozmiar etykiety">
            <button type="button" id="ocrSmallModeBtn" aria-pressed="true">Mała</button>
            <button type="button" id="ocrLargeModeBtn" aria-pressed="false">Duża</button>
        </div>
    </div>
    <div style="position:relative; overflow:hidden;">
        <video id="ocrVideo" autoplay playsinline muted></video>
        <div id="ocrFrame" class="ocr-frame" aria-hidden="true"></div>
        <p id="ocrTip" class="ocr-tip">Mała etykieta: podejdź bliżej i dotknij ekranu, aby ustawić ostrość.</p>
        <div id="ocrFocusMessage" class="ocr-focus-message hidden" aria-live="polite">Ustawiam ostrość...</div>
    </div>
    <div class="ocr-footer">
        <button type="button" id="ocrScanBtn" class="primary">Skanuj</button>
        <button type="button" id="ocrCancelBtn" class="ghost">Anuluj</button>
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
    const partNumberInput = document.getElementById('part_number');
    const scanPartNumberBtn = document.getElementById('scanPartNumberBtn');
    const scanStatus = document.getElementById('scanStatus');
    const ocrModal = document.getElementById('ocrModal');
    const ocrVideo = document.getElementById('ocrVideo');
    const ocrFrame = document.getElementById('ocrFrame');
    const ocrPreview = ocrVideo?.parentElement;
    const ocrScanBtn = document.getElementById('ocrScanBtn');
    const ocrCancelBtn = document.getElementById('ocrCancelBtn');
    const ocrFocusMessage = document.getElementById('ocrFocusMessage');
    const ocrTip = document.getElementById('ocrTip');
    const ocrSmallModeBtn = document.getElementById('ocrSmallModeBtn');
    const ocrLargeModeBtn = document.getElementById('ocrLargeModeBtn');
    let ocrStream = null;
    let tesseractLoadPromise = null;
    let currentScanMode = 'small';
    const scanModes = {
        small: {
            label: 'Mała etykieta',
            tip: 'Mała etykieta: podejdź bliżej i dotknij ekranu, aby ustawić ostrość.',
            frameHeightPx: 42,
            frameWidthRatio: 0.86,
            upscale: 4,
            focusDelayMs: 1000,
            frameDelaysMs: [800, 1200, 1600],
            contrast: 1.4,
            sharpen: true,
            threshold: true,
        },
        large: {
            label: 'Duża etykieta',
            tip: 'Duża etykieta: odsuń telefon tak, aby w ramce był tylko jeden wiersz kodu.',
            frameHeightPx: 60,
            frameWidthRatio: 0.94,
            upscale: 2,
            focusDelayMs: 500,
            frameDelaysMs: [500, 900],
            contrast: 1.2,
            sharpen: false,
            threshold: false,
        },
    };
    const ignoredOcrWords = new Set(['BOSCH', 'VWAG', 'VW', 'AG', 'MADEINROMANIA', 'MADEIN', 'ROMANIA', 'GERMANY']);
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));


    const applyScanMode = mode => {
        currentScanMode = scanModes[mode] ? mode : 'small';
        const config = scanModes[currentScanMode];
        ocrFrame?.style.setProperty('--ocr-frame-height', `${config.frameHeightPx}px`);
        ocrFrame?.style.setProperty('--ocr-frame-width', `${config.frameWidthRatio * 100}vw`);
        ocrTip?.style.setProperty('--ocr-tip-offset', `${(config.frameHeightPx / 2) + 16}px`);
        if (ocrTip) ocrTip.textContent = config.tip;
        ocrSmallModeBtn?.setAttribute('aria-pressed', currentScanMode === 'small' ? 'true' : 'false');
        ocrLargeModeBtn?.setAttribute('aria-pressed', currentScanMode === 'large' ? 'true' : 'false');
    };

    const setScanStatus = (message, isError = false) => {
        if (!scanStatus) return;
        scanStatus.textContent = message;
        scanStatus.classList.toggle('error', isError);
        scanStatus.classList.remove('hidden');
    };
    const normalizeOcrLine = line => line.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const scorePartCode = value => {
        if (value.length < 6 || value.length > 15 || !/\d/.test(value) || ignoredOcrWords.has(value)) return 0;
        let score = 10;
        if (/[A-Z]/.test(value)) score += 8;
        if (/^[A-Z0-9]{2,4}[0-9]{5,8}[A-Z0-9]{0,3}$/.test(value)) score += 18;
        if (/^[0-9][A-Z0-9]{1,3}[0-9]{5,8}[A-Z]?$/.test(value)) score += 8;
        if (/^[A-Z]+$/.test(value) || /^\d+$/.test(value)) score -= 8;
        if (value.length >= 9 && value.length <= 12) score += 4;
        return score;
    };
    const choosePartCode = texts => {
        const joinedText = Array.isArray(texts) ? texts.join('\n') : (texts || '');
        const candidates = joinedText
            .split(/[^A-Z0-9]+/i)
            .map(normalizeOcrLine)
            .filter(Boolean);
        joinedText.split(/\r?\n/).forEach(line => {
            const normalized = normalizeOcrLine(line);
            if (normalized) candidates.push(normalized);
        });
        return candidates
            .map(value => ({ value, score: scorePartCode(value) }))
            .filter(candidate => candidate.score >= 18)
            .sort((a, b) => b.score - a.score || b.value.length - a.value.length)[0]?.value || '';
    };
    const loadTesseract = () => {
        if (window.Tesseract) return Promise.resolve(window.Tesseract);
        if (!tesseractLoadPromise) {
            tesseractLoadPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
                script.onload = () => resolve(window.Tesseract);
                script.onerror = () => reject(new Error('Nie można załadować OCR.'));
                document.head.appendChild(script);
            });
        }
        return tesseractLoadPromise;
    };
    const stopOcrCamera = () => { ocrStream?.getTracks().forEach(track => track.stop()); ocrStream = null; if (ocrVideo) ocrVideo.srcObject = null; };
    const closeOcr = () => { stopOcrCamera(); ocrModal?.classList.add('hidden'); };
    const showFocusMessage = () => {
        ocrFocusMessage?.classList.remove('hidden');
        setTimeout(() => ocrFocusMessage?.classList.add('hidden'), 1100);
    };
    const requestCameraFocus = async () => {
        showFocusMessage();
        const track = ocrStream?.getVideoTracks()[0];
        const capabilities = track?.getCapabilities?.() || {};
        const constraints = { advanced: [] };
        if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('continuous')) constraints.advanced.push({ focusMode: 'continuous' });
        if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('single-shot')) constraints.advanced.push({ focusMode: 'single-shot' });
        if (constraints.advanced.length) {
            try { await track.applyConstraints(constraints); } catch (error) { /* Best-effort browser support. */ }
        }
        await sleep(scanModes[currentScanMode].focusDelayMs);
    };
    const cropFrameToCanvas = () => {
        const videoRect = ocrVideo.getBoundingClientRect();
        const frameRect = ocrFrame.getBoundingClientRect();
        const scaleX = ocrVideo.videoWidth / videoRect.width;
        const scaleY = ocrVideo.videoHeight / videoRect.height;
        const sourceX = Math.round((frameRect.left - videoRect.left) * scaleX);
        const sourceY = Math.round((frameRect.top - videoRect.top) * scaleY);
        const sourceWidth = Math.round(frameRect.width * scaleX);
        const sourceHeight = Math.round(frameRect.height * scaleY);
        const mode = scanModes[currentScanMode];
        const outputScale = mode.upscale;
        const canvas = document.createElement('canvas');
        canvas.width = sourceWidth * outputScale;
        canvas.height = sourceHeight * outputScale;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.imageSmoothingEnabled = false;
        context.drawImage(ocrVideo, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, canvas.width, canvas.height);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);
        const data = image.data;
        for (let i = 0; i < data.length; i += 4) {
            const gray = (data[i] * 0.299) + (data[i + 1] * 0.587) + (data[i + 2] * 0.114);
            const contrasted = Math.max(0, Math.min(255, (gray - 128) * mode.contrast + 128));
            const sharpened = mode.sharpen ? Math.max(0, Math.min(255, (contrasted - 128) * 1.08 + 128)) : contrasted;
            const value = mode.threshold && sharpened > 176 ? 255 : (mode.threshold && sharpened < 78 ? 0 : sharpened);
            data[i] = data[i + 1] = data[i + 2] = value;
        }
        context.putImageData(image, 0, 0);
        return canvas;
    };
    scanPartNumberBtn?.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) { setScanStatus('Kamera nie jest dostępna w tej przeglądarce. Wpisz kod ręcznie.', true); return; }
        try {
            ocrStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 }, focusMode: { ideal: 'continuous' } }, audio: false });
            ocrVideo.srcObject = ocrStream;
            applyScanMode('small');
            ocrModal.classList.remove('hidden');
            setScanStatus('Ustaw tylko jeden wiersz kodu części w ramce i kliknij Skanuj.');
        } catch (error) {
            setScanStatus('Nie udało się uruchomić kamery. Wpisz kod ręcznie.', true);
        }
    });
    ocrSmallModeBtn?.addEventListener('click', () => applyScanMode('small'));
    ocrLargeModeBtn?.addEventListener('click', () => applyScanMode('large'));
    ocrPreview?.addEventListener('click', requestCameraFocus);
    ocrCancelBtn?.addEventListener('click', closeOcr);
    ocrScanBtn?.addEventListener('click', async () => {
        if (!ocrVideo?.videoWidth) return;
        ocrScanBtn.disabled = true; ocrScanBtn.textContent = 'Czytam kod...'; setScanStatus('Czytam kod...');
        try {
            const Tesseract = await loadTesseract();
            await requestCameraFocus();
            const texts = [];
            for (const delay of scanModes[currentScanMode].frameDelaysMs) {
                await sleep(delay);
                const result = await Tesseract.recognize(cropFrameToCanvas(), 'eng', { logger: () => {}, tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' });
                texts.push(result?.data?.text || '');
            }
            const code = choosePartCode(texts);
            if (!code) { setScanStatus('Nie udało się pewnie rozpoznać kodu. Spróbuj ponownie albo wpisz ręcznie.', true); return; }
            partNumberInput.value = code;
            partNumberInput.dispatchEvent(new Event('input', { bubbles: true }));
            setScanStatus(`Rozpoznano kod: ${code}`);
            closeOcr();
        } catch (error) {
            setScanStatus('Nie udało się pewnie rozpoznać kodu. Spróbuj ponownie albo wpisz ręcznie.', true);
        } finally {
            ocrScanBtn.disabled = false; ocrScanBtn.textContent = 'Skanuj';
        }
    });

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
