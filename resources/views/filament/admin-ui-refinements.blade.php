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
    .fi-sidebar-group-button-icon,
    .fi-sidebar-collapse-button,
    .fi-sidebar-close-overlay-button,
    .fi-sidebar-open-button {
        color: var(--gps-admin-navy) !important;
    }

    .fi-sidebar-item-active .fi-sidebar-item-icon,
    .fi-sidebar-item-button:hover .fi-sidebar-item-icon,
    .fi-sidebar-group-button:hover .fi-sidebar-group-button-icon {
        color: var(--gps-admin-navy) !important;
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
</style>
