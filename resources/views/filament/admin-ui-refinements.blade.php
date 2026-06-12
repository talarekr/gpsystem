<style>
    :root {
        --gps-admin-navy: #0B1F3A;
        --gps-admin-border: #e5e7eb;
        --sidebar-width: 14.5rem;
    }

    html,
    body,
    .fi-body,
    .fi-layout,
    .fi-main-ctn,
    .fi-main,
    .fi-page,
    .fi-topbar,
    .fi-topbar nav,
    .fi-sidebar,
    .fi-sidebar-header,
    .fi-sidebar-nav,
    .fi-sidebar-nav-groups,
    .fi-sidebar-group,
    .fi-sidebar-group-items {
        background-color: #ffffff !important;
    }

    .fi-sidebar {
        width: var(--sidebar-width) !important;
        max-width: var(--sidebar-width) !important;
        border-right: 1px solid var(--gps-admin-border);
    }

    .fi-sidebar-header {
        padding-inline: 0.875rem !important;
    }

    .fi-sidebar-nav {
        padding-inline: 0.5rem !important;
    }

    .fi-sidebar-item-button {
        column-gap: 0.625rem;
        padding-inline: 0.75rem !important;
    }

    .fi-sidebar-item-icon,
    .fi-sidebar-item-button .fi-icon,
    .fi-sidebar-item-button svg,
    .fi-sidebar-group-button-icon,
    .fi-sidebar-group-button .fi-icon,
    .fi-sidebar-group-button svg,
    .fi-sidebar-collapse-button,
    .fi-sidebar-collapse-button svg,
    .fi-sidebar-close-overlay-button,
    .fi-sidebar-close-overlay-button svg,
    .fi-sidebar-open-button,
    .fi-sidebar-open-button svg {
        color: var(--gps-admin-navy) !important;
        stroke: var(--gps-admin-navy) !important;
    }

    .fi-sidebar-item-icon [stroke],
    .fi-sidebar-item-button svg [stroke],
    .fi-sidebar-group-button-icon [stroke],
    .fi-sidebar-group-button svg [stroke],
    .fi-sidebar-collapse-button svg [stroke],
    .fi-sidebar-close-overlay-button svg [stroke],
    .fi-sidebar-open-button svg [stroke] {
        stroke: var(--gps-admin-navy) !important;
    }

    .fi-sidebar-item-icon [fill]:not([fill="none"]),
    .fi-sidebar-item-button svg [fill]:not([fill="none"]),
    .fi-sidebar-group-button-icon [fill]:not([fill="none"]),
    .fi-sidebar-group-button svg [fill]:not([fill="none"]),
    .fi-sidebar-collapse-button svg [fill]:not([fill="none"]),
    .fi-sidebar-close-overlay-button svg [fill]:not([fill="none"]),
    .fi-sidebar-open-button svg [fill]:not([fill="none"]) {
        fill: var(--gps-admin-navy) !important;
    }

    .fi-sidebar-item-active .fi-sidebar-item-icon,
    .fi-sidebar-item-active .fi-sidebar-item-button .fi-icon,
    .fi-sidebar-item-active .fi-sidebar-item-button svg,
    .fi-sidebar-item-button:hover .fi-sidebar-item-icon,
    .fi-sidebar-item-button:hover .fi-icon,
    .fi-sidebar-item-button:hover svg,
    .fi-sidebar-group-button:hover .fi-sidebar-group-button-icon,
    .fi-sidebar-group-button:hover .fi-icon,
    .fi-sidebar-group-button:hover svg {
        color: var(--gps-admin-navy) !important;
        stroke: var(--gps-admin-navy) !important;
    }

    .fi-section,
    .fi-ta-ctn,
    .fi-card,
    .fi-wi-widget,
    .fi-dropdown-panel,
    .fi-modal-window {
        background-color: #ffffff;
        border-color: var(--gps-admin-border);
    }
    .gps-car-form-section.fi-section {
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 1px 2px rgba(11, 31, 58, 0.04), 0 0 0 1px rgba(11, 31, 58, 0.025);
    }

    .gps-car-form-section.fi-section .fi-section-header {
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .gps-car-form-section.fi-section .fi-section-header-icon,
    .gps-car-form-section.fi-section .fi-section-header-icon svg,
    .gps-car-form-section.fi-section .fi-section-header .fi-icon,
    .gps-car-form-section.fi-section .fi-section-header svg {
        color: var(--gps-admin-navy) !important;
        stroke: var(--gps-admin-navy) !important;
    }

    .gps-car-form-section.fi-section .fi-section-header-heading {
        color: var(--gps-admin-navy);
    }

</style>
