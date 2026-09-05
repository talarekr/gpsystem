{{--
    This must be the first panel script.  In particular it must run before
    Livewire Navigate gets an opportunity to consume a redirected response and
    transplant the login document into the current document.

    Do not add data-navigate-once here. A full document owns this guard, while
    the idempotence flag makes re-evaluation during an ordinary SPA visit safe.
--}}
<script>
    (() => {
        if (window.__gpsAdminAuthBoundaryInstalled) return;

        window.__gpsAdminAuthBoundaryInstalled = true;

        const nativeFetch = window.fetch.bind(window);
        const isLivewireRequest = (input, init) => {
            const request = input instanceof Request ? input : null;
            const url = request?.url || String(input);
            const headers = new Headers(init?.headers || request?.headers);

            return new URL(url, window.location.href).pathname === '/livewire/update'
                || headers.has('X-Livewire-Navigate');
        };
        const isAuthDestination = (value) => {
            try {
                const pathname = new URL(value, window.location.href).pathname.replace(/\/+$/, '');

                return pathname === '/admin/login' || pathname === '/admin/logout';
            } catch {
                return false;
            }
        };
        const hardNavigate = (url) => {
            window.location.assign(new URL(url, window.location.href).href);

            // Never hand an auth response back to Livewire Navigate. Keeping
            // the request pending prevents a document swap until navigation
            // tears down this JavaScript realm.
            return new Promise(() => {});
        };
        const findAuthRedirect = (value) => {
            if (typeof value === 'string') return isAuthDestination(value) ? value : null;
            if (! value || typeof value !== 'object') return null;

            for (const [key, child] of Object.entries(value)) {
                if ((key === 'redirect' || key === 'url') && typeof child === 'string' && isAuthDestination(child)) {
                    return child;
                }

                const match = findAuthRedirect(child);
                if (match) return match;
            }

            return null;
        };

        window.fetch = async (...arguments_) => {
            const response = await nativeFetch(...arguments_);

            if (! isLivewireRequest(arguments_[0], arguments_[1])) return response;

            // fetch follows the authentication middleware's HTTP redirect.
            // response.url is therefore the only point at which it can be
            // stopped before Livewire installs the returned login HTML.
            if (response.redirected && isAuthDestination(response.url)) {
                return hardNavigate(response.url);
            }

            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                try {
                    const redirect = findAuthRedirect(await response.clone().json());
                    if (redirect) return hardNavigate(redirect);
                } catch {
                    // The original response remains untouched for Livewire.
                }
            }

            return response;
        };

        // Filament's SPA URL exceptions cover normal links. Capture these as a
        // second, framework-independent guarantee for dynamically-added links.
        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (! link || ! isAuthDestination(link.href)) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            window.location.assign(link.href);
        }, true);

        document.addEventListener('livewire:navigate', (event) => {
            const destination = event.detail?.url?.href || event.detail?.url;
            if (! isAuthDestination(destination)) return;

            event.preventDefault();
            window.location.assign(destination);
        });
    })();
</script>
