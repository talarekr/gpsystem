<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
    (() => {
        const csrfRefreshUrl = @js(route('admin.csrf-token'));
        const loginUrl = @js(url('/admin/login'));
        const refreshIntervalMs = 10 * 60 * 1000;
        let refreshPromise = null;

        async function refreshCsrfToken() {
            if (refreshPromise) return refreshPromise;

            refreshPromise = fetch(csrfRefreshUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            })
                .then(async (response) => {
                    if (! response.ok) throw new Error(`CSRF refresh failed with HTTP ${response.status}`);

                    const data = await response.json();
                    if (! data.token) throw new Error('CSRF refresh response did not include a token.');

                    document.querySelectorAll('meta[name="csrf-token"]').forEach((element) => {
                        element.setAttribute('content', data.token);
                    });

                    document.querySelectorAll('input[name="_token"]').forEach((element) => {
                        element.value = data.token;
                    });

                    window.Livewire?.csrfToken && (window.Livewire.csrfToken = data.token);
                    window.livewireScriptConfig && (window.livewireScriptConfig.csrf = data.token);

                    return data.token;
                })
                .finally(() => {
                    refreshPromise = null;
                });

            return refreshPromise;
        }

        window.GpsAdminSession = { refreshCsrfToken };

        document.addEventListener('visibilitychange', () => {
            if (! document.hidden) refreshCsrfToken().catch(() => undefined);
        });

        window.addEventListener('focus', () => refreshCsrfToken().catch(() => undefined));
        setInterval(() => refreshCsrfToken().catch(() => undefined), refreshIntervalMs);

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (! (form instanceof HTMLFormElement)) return;
            if (! /^(POST|PUT|PATCH|DELETE)$/i.test(form.method || 'GET')) return;

            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const input = form.querySelector('input[name="_token"]');
            if (token && input) input.value = token;
        }, true);

        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (status !== 419) return;

                    preventDefault();
                    alert('Sesja panelu administracyjnego wygasła. Zostaniesz przekierowany do logowania.');
                    window.location.assign(loginUrl);
                });
            });
        });
    })();
</script>
