(function () {
    const menus = document.querySelectorAll('[data-category-menu]');

    menus.forEach((menu) => {
        const triggers = menu.querySelectorAll('[data-root-id]');
        const panels = menu.querySelectorAll('[data-root-panel]');

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

        triggers.forEach((trigger) => {
            trigger.addEventListener('mouseenter', () => activate(trigger.dataset.rootId));
            trigger.addEventListener('focus', () => activate(trigger.dataset.rootId));
            trigger.addEventListener('click', () => activate(trigger.dataset.rootId));
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
