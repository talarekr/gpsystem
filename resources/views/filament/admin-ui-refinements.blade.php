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


    .fi-main:has(.fi-ta),
    .fi-main:has(.fi-ta) > .mx-auto,
    .fi-main:has(.fi-ta) .fi-page,
    .fi-main:has(.fi-ta) .fi-page > .mx-auto {
        width: 100% !important;
        max-width: none !important;
    }

    .fi-main:has(.fi-ta) {
        padding-inline: clamp(1rem, 2vw, 2rem) !important;
    }

    .fi-main:has(.fi-ta) .fi-ta-ctn {
        overflow-x: auto;
        border: 1px solid var(--gps-admin-border);
        box-shadow: 0 1px 2px rgba(11, 31, 58, 0.04);
    }

    .fi-main:has(.fi-ta) .fi-ta-table {
        min-width: 78rem;
    }

    .fi-main:has(.fi-ta) .fi-ta-row,
    .fi-main:has(.fi-ta) .fi-ta-cell {
        line-height: 1.25rem;
    }

    .gps-car-parts-stack {
        display: inline-flex;
        min-width: 7.5rem;
        flex-direction: column;
        gap: 0.25rem;
        white-space: nowrap;
    }

    .gps-car-parts-stack span {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        border: 1px solid #dbe3ee;
        border-radius: 999px;
        background: #f8fafc;
        color: #0f172a;
        padding: 0.125rem 0.55rem;
        font-size: 0.75rem;
        font-weight: 650;
        line-height: 1rem;
    }

    .gps-car-parts-stack span:first-child {
        border-color: #cbd5e1;
        background: #f1f5f9;
        color: var(--gps-admin-navy);
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


    .gps-car-photo-upload .filepond--root {
        margin-bottom: 0;
        min-height: 8rem;
        overflow: visible;
        border: 1px dashed #cbd5e1;
        border-radius: 0.875rem;
        background: #ffffff;
    }

    .gps-car-photo-upload .filepond--drop-label {
        min-height: 7rem;
        border: 0;
        border-radius: 0.875rem;
        background: #ffffff;
        color: #475569;
    }

    .gps-car-photo-upload .filepond--drop-label label {
        padding: 0.875rem;
        font-size: 0.875rem;
        line-height: 1.25rem;
    }

    .gps-car-photo-upload .filepond--list-scroller {
        position: relative !important;
        inset: auto !important;
        margin: 0.75rem !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        transform: none !important;
    }

    .gps-car-photo-upload .filepond--list {
        position: relative !important;
        display: flex !important;
        min-height: 6rem;
        gap: 0.625rem;
        transform: none !important;
    }

    .gps-car-photo-upload .filepond--item {
        position: relative !important;
        top: auto !important;
        left: auto !important;
        flex: 0 0 6rem;
        width: 6rem !important;
        min-width: 6rem !important;
        height: 6rem !important;
        margin: 0 !important;
        overflow: hidden;
        transform: none !important;
        border: 1px solid #dbe3ee;
        border-radius: 0.75rem;
        background: #ffffff;
        box-shadow: 0 1px 2px rgba(11, 31, 58, 0.08);
    }

    .gps-car-photo-upload .filepond--item:first-child {
        border-color: var(--gps-admin-navy);
        box-shadow: 0 0 0 1px rgba(11, 31, 58, 0.25), 0 8px 18px rgba(11, 31, 58, 0.08);
    }

    .gps-car-photo-upload .filepond--item:first-child::after {
        position: absolute;
        top: 0.375rem;
        left: 0.375rem;
        z-index: 8;
        padding: 0.125rem 0.45rem;
        border-radius: 999px;
        background: var(--gps-admin-navy);
        color: #ffffff;
        content: 'Główny';
        font-size: 0.625rem;
        font-weight: 700;
        line-height: 1rem;
        letter-spacing: 0.01em;
        pointer-events: none;
    }

    .gps-car-photo-upload .filepond--panel-root {
        border-radius: 0.875rem;
        background: #ffffff;
    }

    .gps-car-photo-upload .filepond--image-preview-wrapper,
    .gps-car-photo-upload .filepond--image-preview,
    .gps-car-photo-upload .filepond--image-bitmap,
    .gps-car-photo-upload .filepond--image-bitmap canvas {
        height: 6rem !important;
        min-height: 6rem !important;
        border-radius: 0.75rem;
    }

    .gps-car-photo-upload .filepond--file {
        min-height: 6rem;
    }

    .gps-car-photo-upload .filepond--file-info {
        display: none;
    }

    .gps-car-photo-upload .filepond--file-action-button,
    .gps-car-photo-upload .filepond--file-action-button:hover,
    .gps-car-photo-upload .filepond--file-action-button:focus {
        background: var(--gps-admin-navy);
        color: #ffffff;
        box-shadow: 0 1px 2px rgba(11, 31, 58, 0.18);
    }

    .gps-car-photo-upload .filepond--file-action-button svg,
    .gps-car-photo-upload .filepond--file-action-button svg [stroke] {
        stroke: #ffffff !important;
    }

    .gps-car-photo-upload .filepond--drip,
    .gps-car-photo-upload .filepond--credits {
        display: none;
    }

</style>
