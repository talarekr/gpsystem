(() => {
  const body = document.body;
  const toggle = document.querySelector('[data-gps-sidebar-toggle]');
  const storageKey = 'gps-admin-sidebar-collapsed';

  const applySidebarState = (collapsed) => {
    body.classList.toggle('gps-admin-sidebar-collapsed', collapsed);
    toggle?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  };

  applySidebarState(localStorage.getItem(storageKey) === '1');

  toggle?.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 1023px)').matches) {
      document.querySelector('.fi-sidebar-open-button')?.click();
      return;
    }

    const collapsed = !body.classList.contains('gps-admin-sidebar-collapsed');
    localStorage.setItem(storageKey, collapsed ? '1' : '0');
    applySidebarState(collapsed);
  });

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
