(function () {
    const menus = document.querySelectorAll('[data-category-menu]');

    menus.forEach((menu) => {
        const toggle = menu.querySelector('summary');
        const panel = menu.querySelector('.sf-category-menu__panel');
        const triggers = menu.querySelectorAll('[data-root-id]');
        const panels = menu.querySelectorAll('[data-root-panel]');

        function setOpen(isOpen) {
            menu.open = isOpen;
            menu.classList.toggle('is-open', isOpen);
            toggle?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        function activate(rootId) {
            triggers.forEach((trigger) => {
                const isActive = trigger.dataset.rootId === rootId;
                trigger.classList.toggle('is-active', isActive);
                trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            panels.forEach((panel) => {
                const isActive = panel.dataset.rootPanel === rootId;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });
        }

        setOpen(menu.open);

        toggle?.setAttribute('aria-haspopup', 'true');

        toggle?.addEventListener('click', (event) => {
            event.preventDefault();
            setOpen(!menu.open);
        });

        menu.addEventListener('toggle', () => {
            const isOpen = menu.open;

            if (menu.classList.contains('is-open') !== isOpen) {
                setOpen(isOpen);
            }
        });

        panel?.addEventListener('click', (event) => {
            event.stopPropagation();
        });

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => activate(trigger.dataset.rootId));
        });

        document.addEventListener('click', (event) => {
            if (!menu.open || toggle?.contains(event.target) || panel?.contains(event.target)) {
                return;
            }

            setOpen(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !menu.open) {
                return;
            }

            setOpen(false);
            toggle?.focus();
        });
    });

    document.querySelectorAll('[data-category-tree-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const item = toggle.closest('[data-category-tree-item]');
            const children = item ? item.querySelector(':scope > [data-category-tree-children]') : null;

            if (!children) {
                return;
            }

            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            const nextExpanded = !isExpanded;
            const label = toggle.getAttribute('aria-label') || '';

            children.hidden = !nextExpanded;
            item.classList.toggle('is-branch', nextExpanded);
            toggle.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
            toggle.textContent = nextExpanded ? '−' : '+';
            toggle.setAttribute('aria-label', label.replace(isExpanded ? 'Zwiń' : 'Rozwiń', nextExpanded ? 'Zwiń' : 'Rozwiń'));
        });
    });
})();

(function () {
    const phoneMenu = document.querySelector('[data-phone-menu]');

    if (!phoneMenu) {
        return;
    }

    const toggle = phoneMenu.querySelector('.sf-phones__toggle');

    function setOpen(isOpen) {
        phoneMenu.classList.toggle('is-open', isOpen);
        toggle?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    toggle?.addEventListener('click', (event) => {
        event.preventDefault();
        setOpen(!phoneMenu.classList.contains('is-open'));
    });

    document.addEventListener('click', (event) => {
        if (!phoneMenu.classList.contains('is-open') || phoneMenu.contains(event.target)) {
            return;
        }

        setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !phoneMenu.classList.contains('is-open')) {
            return;
        }

        setOpen(false);
        toggle?.focus();
    });
})();
