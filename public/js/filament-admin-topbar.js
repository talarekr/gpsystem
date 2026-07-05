(() => {
  const body = document.body;
  const toggle = document.querySelector('[data-gps-sidebar-toggle]');
  const storageKey = 'gps-admin-sidebar-collapsed';
  const mobileQuery = window.matchMedia('(max-width: 1023px)');

  const ensureMobileOverlay = () => {
    let overlay = document.querySelector('[data-gps-mobile-sidebar-overlay]');

    if (!overlay) {
      overlay = document.createElement('button');
      overlay.type = 'button';
      overlay.className = 'gps-admin-mobile-sidebar-overlay';
      overlay.dataset.gpsMobileSidebarOverlay = '';
      overlay.setAttribute('aria-label', 'Zamknij menu boczne');
      overlay.addEventListener('click', closeMobileSidebar);
      document.body.appendChild(overlay);
    }

    return overlay;
  };

  const setMobileSidebarState = (open) => {
    body.classList.toggle('gps-admin-mobile-sidebar-open', open);
    toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.querySelector('.fi-sidebar')?.setAttribute('aria-hidden', open ? 'false' : 'true');
  };

  function closeMobileSidebar() {
    setMobileSidebarState(false);
    document.querySelector('.fi-sidebar-close-overlay-button')?.click();
  }

  const openMobileSidebar = () => {
    ensureMobileOverlay();
    setMobileSidebarState(true);
    document.querySelector('.fi-sidebar-open-button')?.click();
  };

  const syncResponsiveSidebarState = () => {
    if (mobileQuery.matches) {
      body.classList.remove('gps-admin-sidebar-collapsed');
      setMobileSidebarState(false);
      return;
    }

    setMobileSidebarState(false);
    applySidebarState(localStorage.getItem(storageKey) === '1');
  };

  const applySidebarState = (collapsed) => {
    body.classList.toggle('gps-admin-sidebar-collapsed', collapsed);
    toggle?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  };

  if (mobileQuery.matches) {
    toggle?.setAttribute('aria-expanded', 'false');
    document.querySelector('.fi-sidebar')?.setAttribute('aria-hidden', 'true');
  } else {
    applySidebarState(localStorage.getItem(storageKey) === '1');
  }

  toggle?.addEventListener('click', () => {
    if (mobileQuery.matches) {
      body.classList.contains('gps-admin-mobile-sidebar-open') ? closeMobileSidebar() : openMobileSidebar();
      return;
    }

    const collapsed = !body.classList.contains('gps-admin-sidebar-collapsed');
    localStorage.setItem(storageKey, collapsed ? '1' : '0');
    applySidebarState(collapsed);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && body.classList.contains('gps-admin-mobile-sidebar-open')) {
      closeMobileSidebar();
    }
  });

  document.querySelector('.fi-sidebar')?.addEventListener('click', (event) => {
    if (mobileQuery.matches && event.target.closest('a[href]')) {
      closeMobileSidebar();
    }
  });

  mobileQuery.addEventListener?.('change', syncResponsiveSidebarState);

  const root = document.querySelector('[data-gps-part-search]');
  if (!root) return;

  const endpoint = root.dataset.endpoint;
  const input = root.querySelector('[data-gps-part-search-input]');
  const results = root.querySelector('[data-gps-part-search-results]');
  let timer;
  let controller;

  const hide = () => {
    results.hidden = true;
    results.innerHTML = '';
  };

  const render = (items) => {
    if (!items.length) {
      results.innerHTML = '<div class="gps-admin-search__empty">Brak wyników.</div>';
      results.hidden = false;
      return;
    }

    results.innerHTML = items.map((item) => `
      <a class="gps-admin-search__result" href="${item.url}">
        <span class="gps-admin-search__thumb">${item.thumbnail ? `<img src="${item.thumbnail}" alt="">` : '—'}</span>
        <span class="gps-admin-search__meta">
          <strong>${escapeHtml(item.name || 'Część #' + item.id)}</strong>
          <small>${escapeHtml([item.sku, item.part_number].filter(Boolean).join(' · ') || 'Brak numeru')}</small>
        </span>
        <span class="gps-admin-search__side">
          <small>${escapeHtml(item.price || '')}</small>
          <em>${escapeHtml(item.status || '')}</em>
        </span>
      </a>
    `).join('');
    results.hidden = false;
  };

  const search = () => {
    const q = input.value.trim();
    if (q.length < 2) {
      hide();
      return;
    }

    controller?.abort();
    controller = new AbortController();

    fetch(`${endpoint}?q=${encodeURIComponent(q)}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then((response) => response.ok ? response.json() : Promise.reject())
      .then((payload) => render(payload.data || []))
      .catch((error) => {
        if (error.name !== 'AbortError') hide();
      });
  };

  input?.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
  });

  input?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') hide();
    if (event.key === 'Enter') {
      const first = results.querySelector('a');
      if (first && !results.hidden) {
        event.preventDefault();
        first.click();
      }
    }
  });

  document.addEventListener('click', (event) => {
    if (!root.contains(event.target)) hide();
  });

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[char]));
  }
})();
