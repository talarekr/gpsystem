# GPS Product Hub — MVP Specification

## Purpose

This document defines the first safe MVP for GPS Product Hub. It exists to keep implementation work small, reviewable, and production-safe. Codex and future developers should use this document together with:

- `docs/gps-product-hub-blueprint.md`
- `docs/gps-product-hub-implementation-plan.md`
- `docs/gps-product-hub-mvp-specification.md`

The MVP must not attempt to build the full long-term commerce platform. The MVP is the foundation required to replace unstructured intake and prepare safe WooCommerce synchronization while avoiding premature marketplace publishing.

## MVP objective

The MVP objective is:

> Staff can log in to GPS Product Hub, use a basic admin skeleton, capture or create staging items, attach core product intake data, review readiness blockers, promote approved staging items into central products, and prepare for safe WooCommerce sync without enabling direct marketplace publishing.

## MVP priorities

Priority order:

1. Laravel/Filament foundation.
2. Authentication and roles.
3. Admin skeleton and navigation.
4. Staging item foundation.
5. Mobile-friendly intake foundation.
6. Product catalog foundation.
7. Basic media/photo handling.
8. Basic OEM normalization and duplicate candidate checks.
9. Basic readiness checks.
10. WooCommerce sync bridge in dry-run/controlled mode.

## Explicit MVP non-goals

The MVP must not include:

- Broad eBay DE/FR production publishing.
- Allegro publishing.
- Ovoko publishing.
- External API writes without explicit approval.
- Native Laravel storefront checkout.
- Partner/vendor portal.
- Advanced OCR automation.
- Offline-first mobile sync.
- Fully automated pricing.
- Fully automated translation.
- Complex warehouse management.
- Direct marketplace stock/order control as a production dependency.

API read-only research and sandbox validation may be planned separately, but must not become production write workflows during MVP foundation work.

## Implementation process rules

Codex should implement GPS Product Hub as small, reviewable tickets.

Rules:

- Do not build the whole system at once.
- Do not implement modules that are not part of the current ticket.
- Keep changes small and easy to review.
- Prefer clean Laravel conventions over clever abstractions.
- Follow the planning documents listed in this specification.
- Always run relevant tests/checks before finishing.
- Do not introduce marketplace publishing until the MVP foundation is stable.
- Do not add external API writes without explicit approval.
- Use feature flags or disabled-by-default behavior for risky integrations.
- Keep production safety in mind from the beginning.

Each implementation step must report:

- What was changed.
- Why it was changed.
- Files created or modified.
- Migrations added.
- Tests added, or why tests were not added.
- Commands run.
- Any risks, disabled features, or follow-up work.

## Ticket size guidance

A good ticket should usually be small enough to review in one pull request.

Preferred ticket characteristics:

- One clear goal.
- One primary module or boundary.
- Minimal migrations.
- Tests for changed behavior where practical.
- No hidden marketplace side effects.
- No unrelated refactors.
- No speculative abstractions.

Avoid tickets that combine unrelated areas such as auth, products, eBay publishing, pricing, and mobile intake in one change.

## Recommended MVP ticket sequence

### Ticket 1 — Laravel/Filament foundation and basic auth/admin skeleton

This should be the first implementation ticket unless explicitly requested otherwise.

Scope:

- Create or initialize the Laravel application structure if not already present.
- Install/configure Filament admin.
- Configure authentication.
- Add basic roles/permissions foundation.
- Add initial admin dashboard shell.
- Add placeholder navigation for planned operational modules, disabled if not implemented.
- Add initial environment/test setup.

Out of scope:

- Staging item workflow.
- Product catalog tables.
- WooCommerce sync.
- Marketplace publishing.
- External API writes.

Definition of done:

- Admin user can log in locally.
- Basic dashboard loads.
- Role/permission approach is present or clearly prepared.
- Tests/checks pass.
- No marketplace integration is enabled.

### Ticket 2 — Settings, channels, shipping groups, and audit foundation

Scope:

- Add configuration foundation for channels and shipping groups.
- Seed or create records for `shipping_30`, `shipping_50`, and `shipping_130`.
- Add basic audit logging foundation for admin changes.
- Keep external integrations disabled by default.

Out of scope:

- Product creation.
- Marketplace API calls.
- Woo sync writes.

### Ticket 3 — Staging item model and admin screens

Scope:

- Add staging item migration/model.
- Add required staging statuses.
- Add admin list/detail/create screens.
- Add status badges and blocking reason fields.
- Add basic audit/event history for staging status changes.

Out of scope:

- Mobile camera capture.
- Product promotion.
- External enrichment.

### Ticket 4 — Media/photo foundation for staging

Scope:

- Add image upload support for staging items.
- Store image metadata.
- Generate simple previews if practical.
- Show photos in staging admin.

Out of scope:

- Marketplace image export.
- Advanced image processing.
- OCR.

### Ticket 5 — Mobile intake MVP screen

Scope:

- Add mobile-friendly intake form.
- Capture OEM/part number, condition, location/bin, notes, and photos.
- Create staging item from phone.
- Show upload progress/error states where practical.

Out of scope:

- Offline-first sync.
- OCR automation.
- Product promotion.

### Ticket 6 — OEM normalization and duplicate candidates

Scope:

- Add normalized OEM field/logic.
- Add duplicate candidate detection for staging items.
- Show duplicate warnings in admin.

Out of scope:

- Automatic duplicate merging.
- Marketplace duplicate detection.

### Ticket 7 — Product catalog foundation

Scope:

- Add products, product identifiers, product images relation, categories, and basic attributes as needed for MVP.
- Add admin product list/detail screens.
- Keep catalog independent from Woo IDs.

Out of scope:

- Full vehicle fitment system unless required for MVP.
- Marketplace listing records.

### Ticket 8 — Promote staging item to product

Scope:

- Add controlled promotion action from staging to product.
- Preserve source history.
- Copy OEM, photos, notes, condition, category/price where available.
- Audit promotion.

Out of scope:

- Auto-promotion.
- Marketplace publishing.

### Ticket 9 — Basic readiness checks

Scope:

- Add product/staging readiness service.
- Check missing OEM, missing images, missing price, missing category, duplicate candidate, and missing stock/location where relevant.
- Show blockers and warnings in admin.

Out of scope:

- Full eBay aspect readiness.
- Automated fixes.

### Ticket 10 — WooCommerce import/sync dry-run foundation

Scope:

- Research and document WooCommerce API mapping.
- Add disabled-by-default Woo configuration.
- Add dry-run import/sync shape if approved.
- Store external Woo references separately from internal IDs.

Out of scope:

- Live Woo writes unless explicitly approved.
- eBay/Ovoko/Allegro writes.

## Feature flag and integration safety rules

Risky capabilities must be disabled by default:

- WooCommerce writes.
- eBay publishing.
- eBay stock sync.
- Allegro publishing.
- Ovoko publishing.
- Any external API write operation.
- Bulk publish or bulk stock update actions.

Feature flags or configuration gates should make risky actions unavailable unless explicitly enabled for the environment and user role.

Production safety expectations:

- Dry-run mode should exist before write mode for external systems where practical.
- Write actions should require explicit user action and permission.
- Bulk actions should require confirmation.
- External API responses and errors should be logged.
- Failed jobs should be visible and retryable where safe.

## MVP definition of done

The MVP is done when:

- Laravel/Filament admin foundation is stable.
- Users can log in with appropriate roles.
- Staff can create staging items from admin and mobile-friendly intake.
- Staging items can store OEM/part number, normalized OEM, condition, location/bin, notes, photos, status, warnings, and blocking reasons.
- Duplicate candidates are visible.
- Products can be created from approved staging items.
- Basic products store identifiers, images, category, price, stock/location, and source history.
- Basic readiness checks show blockers and warnings.
- WooCommerce sync has at least a documented, reviewed dry-run or controlled path.
- Risky integrations are disabled by default.
- Tests/checks for implemented behavior pass.
- Implementation documentation for each ticket records changed files, migrations, tests, and commands.

## Review checklist for every implementation PR

Before finishing an implementation PR, confirm:

- The PR matches one ticket or a clearly bounded sub-ticket.
- No unrelated module was implemented.
- No marketplace publishing was introduced accidentally.
- No external API write was introduced without explicit approval.
- Migrations are minimal and reversible where practical.
- Tests or checks were run and documented.
- New admin screens are permission-aware where relevant.
- Feature flags/configuration gates protect risky behavior.
- The final response lists files changed, migrations added, tests/checks run, and any skipped tests with reasons.
