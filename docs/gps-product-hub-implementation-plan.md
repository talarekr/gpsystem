# GPS Product Hub — 6-Month Implementation Plan

## Purpose

This document turns the GPS Product Hub technical blueprint into a practical execution plan for the first six months. It is intentionally planning-only: it does not define Laravel code, migrations, controllers, or UI components yet. The goal is to help a small team build the system in the right order, avoid a big-bang migration, and move business logic out of WordPress/WooCommerce plugins safely.

Primary implementation priorities for the first six months:

1. Establish the Laravel modular monolith foundation.
2. Build mobile intake and staging as the new product entry workflow.
3. Build the central product catalog and stock model.
4. Build readiness checks and operational admin screens.
5. Build a safe WooCommerce sync bridge.
6. Research and validate marketplace APIs before direct marketplace publishing.
7. Postpone high-risk eBay/Ovoko/Allegro publishing until the core hub is stable, except for narrow technical validation if needed.

## Guiding principles

- **Product Hub becomes the source of truth gradually.** Do not switch all operational control at once.
- **Mobile intake first.** Replace Gmail-based intake with a structured PWA staging workflow before replacing marketplace publishing.
- **Staging before product creation.** Every new part should enter a reviewable staging pipeline first.
- **Readiness before publishing.** No channel publishing should happen without readiness results and blocking reasons.
- **Woo sync before direct marketplace publishing.** Since Woo remains the live storefront during migration, Product Hub should first prove it can create and update Woo products safely.
- **Adapters, not dependencies.** Woo, eBay, Allegro, Ovoko, and future channels should be treated as integration adapters.
- **Small-team pragmatism.** Prefer a modular monolith, explicit queues, clear admin screens, and simple operations over premature microservices.
- **Auditability.** Price, stock, readiness, product promotion, and publish actions must be traceable.

## 1. Milestones and deliverables by month

### Month 1 — Foundation, architecture, admin skeleton, staging base

#### Main objective

Create the Laravel Product Hub foundation and the minimum internal structures needed to capture and manage staging items. Month 1 should not attempt marketplace publishing.

#### Deliverables

- Laravel project foundation and repository conventions.
- Modular monolith folder/module structure agreed and documented.
- PostgreSQL database selected and local/staging setup prepared.
- Redis and queue strategy selected, even if only lightly used in Month 1.
- Filament admin skeleton.
- Authentication, users, roles, and permissions foundation.
- Core configuration tables for channels, shipping groups, and internal settings.
- Initial staging item data model.
- Initial product catalog data model skeleton.
- Initial audit log strategy.
- Basic admin screens:
  - Dashboard placeholder.
  - Staging item list/detail.
  - User/role management.
  - Settings for channels and shipping groups.
- Decision log for key technical decisions.

#### Expected end-of-month capability

An admin user can log in, create or view staging items manually, see basic statuses, and manage foundational settings. The system is not yet used for production publishing or stock control.

#### Definition of done

- Product Hub application can run in local and staging environments.
- Filament admin is accessible with role-protected login.
- Staging items can be created manually from admin.
- Initial statuses exist and are visible.
- Shipping group records exist for `shipping_30`, `shipping_50`, and `shipping_130`.
- Basic audit records are created for important admin changes.
- No direct marketplace publishing exists.
- Technical decision log contains database, admin panel, queue, storage, and search choices.

### Month 2 — Mobile intake PWA, photos, OEM capture, duplicate detection

#### Main objective

Make the mobile intake workflow usable by staff so new parts can enter Product Hub without Gmail as the primary workflow.

#### Deliverables

- Mobile-friendly PWA intake screen.
- Camera/photo capture through browser upload/capture APIs.
- Multiple photos per staging item.
- Upload progress and failure handling.
- OEM/part number manual entry.
- OEM normalization rules.
- Location/bin/warehouse field.
- Condition field.
- Notes field.
- Staging item creation from mobile.
- Duplicate detection by normalized OEM/part number.
- Duplicate candidate review screen.
- Background image processing jobs for variants/previews.
- Basic mobile usability testing with real phones.

#### Expected end-of-month capability

Staff can use a phone to capture a part, take multiple photos, enter an OEM/part number and location, and create a staging item. The system warns about likely duplicates.

#### Definition of done

- A staging item can be created from a phone without using Gmail.
- Multiple photos are stored and visible in admin.
- OEM is normalized and stored separately from the raw input.
- Duplicate candidates are shown for matching normalized OEMs.
- Failed uploads can be retried or clearly reported.
- Admin can review mobile-created staging items.
- Intake workflow has been tested with real product examples.

### Month 3 — Enrichment, price research, category/shipping mapping

#### Main objective

Begin turning raw staging items into enriched reviewable records with suggested data. Month 3 is primarily read-only external integration and internal mapping work.

#### Deliverables

- Ovoko API research completed for read-only enrichment.
- Ovoko enrichment adapter prototype if API access is available.
- Allegro API research completed for OEM price research.
- Allegro price research prototype if API access is available.
- NBP exchange rate API research completed.
- Internal category model.
- Initial internal category seed/import approach.
- Category mapping admin screen.
- Shipping group admin screen.
- Suggested category and shipping group workflow.
- Price suggestion table and admin review screen.
- Confidence scoring strategy for price suggestions.
- Enrichment and price research queued jobs.
- Readiness blockers for missing category, price, images, OEM, and duplicate review.

#### Expected end-of-month capability

A staging item can receive enrichment and price suggestions, can be mapped to an internal category and shipping group, and can show readiness blockers that must be fixed before product creation.

#### Definition of done

- Ovoko read-only API feasibility is documented.
- Allegro price research feasibility is documented.
- NBP exchange-rate approach is documented.
- Staging item can store enrichment snapshots and price suggestion snapshots.
- Category and shipping suggestions are visible to admin users.
- Admin can accept or override suggested category/shipping/price.
- No automatic price application occurs without an explicit user action or approved rule.
- External API failures are logged and visible.

### Month 4 — Central product catalog, readiness engine, Woo sync bridge

#### Main objective

Promote reviewed staging items into central products and prove Product Hub can safely create/update WooCommerce products while Woo remains the live storefront.

#### Deliverables

- Product promotion workflow from staging.
- Product catalog admin screens.
- Product identifiers/OEM management.
- Product images management.
- Product category and attributes management.
- Stock item and stock movement foundation.
- Product readiness engine.
- Product-level readiness results with blockers, warnings, and suggested fixes.
- WooCommerce API research completed.
- Woo import job for existing products.
- Woo external reference mapping.
- Woo create/update job from Product Hub to Woo.
- Safe dry-run mode for Woo sync.
- Audit logs for product promotion, price changes, stock changes, and Woo sync actions.

#### Expected end-of-month capability

A staff-reviewed staging item can become a central product. Product Hub can push approved products to Woo in a controlled way, while Woo remains the live storefront.

#### Definition of done

- Product can be created from a staging item without losing photos, OEMs, category, price, stock, and source history.
- Product readiness can be calculated and displayed.
- Woo products can be imported into Product Hub without overwriting Woo.
- Product Hub can create/update Woo products in dry-run and approved live mode.
- Woo external product IDs are stored as external references, not primary Product Hub IDs.
- Stock movement history exists for initial intake and adjustments.
- Failed Woo sync attempts are logged and retryable.

### Month 5 — Channel readiness, eBay DE/FR validation, publish logging foundation

#### Main objective

Prepare the marketplace layer without making marketplace publishing the primary production path. Focus on eBay DE/FR API validation, channel readiness, listing records, logging, duplicate guards, and controlled test publishing only if safe.

#### Deliverables

- eBay DE/FR API research completed.
- Decision on eBay API approach for inventory, offers, categories, aspects, business policies, orders, and stock.
- Channel listing data model implemented if not already completed.
- Channel readiness checks for eBay DE and eBay FR.
- eBay category/aspect mapping research and initial admin screens.
- Business policy configuration storage.
- DE content template rules draft.
- FR content/translation template rules draft.
- FR EUR conversion and markup rule draft using NBP rates.
- Duplicate guard design and initial implementation.
- Publish job record model and logs.
- Error Center for integration and publish failures.
- Optional: sandbox or very limited test publishing validation.

#### Expected end-of-month capability

Product Hub can determine whether a product is ready for eBay DE/FR, explain why it is not ready, and create durable publish job/log records. Real publishing remains limited to test/sandbox or explicitly approved validation items.

#### Definition of done

- eBay API feasibility and required credentials/scopes are documented.
- eBay DE/FR readiness checks can run against products.
- Required category/aspect/policy blockers are visible.
- Channel listing records can store external listing IDs, SKUs, URLs, status, price, quantity, and errors.
- Publish attempts are represented as durable records even if no production publish occurs.
- Duplicate guards prevent accidental second listing for the same product/channel.
- Error Center shows failed channel operations.
- Production eBay publishing is not broadly enabled by default.

### Month 6 — Stabilization, migration testing, storefront planning/MVP decision

#### Main objective

Stabilize the Product Hub workflows, prove end-to-end migration safety, and decide whether to start a minimal Laravel storefront or keep Month 6 focused on operational hardening.

#### Deliverables

- End-to-end test from mobile intake to staging to product to Woo sync.
- Operational dashboard improvements.
- Queue monitoring and failed-job review.
- Backup and restore test.
- Data quality reports for imported Woo products.
- Migration runbook for Phases 1–3.
- Storefront architecture decision.
- Storefront MVP scope document, or first minimal storefront prototype if the core system is stable.
- Security review for roles, credentials, and approval workflows.
- Production readiness checklist for Product Hub as intake/catalog/Woo sync source.

#### Expected end-of-month capability

Product Hub is stable enough to own mobile intake, staging, central product records, readiness, and Woo product sync. Direct marketplace publishing remains gated unless Month 5 validation proved it is safe and operationally approved.

#### Definition of done

- Multiple real products have passed through mobile intake to Woo sync successfully.
- Staff can operate staging and product catalog screens without developer assistance for common cases.
- Backup restore has been tested.
- Error handling and retry procedures are documented.
- Storefront plan is approved, or a minimal storefront MVP exists behind a non-production flag/domain.
- Migration risks and rollback procedures are documented.
- The next six-month roadmap is drafted.

## 2. Recommended module boundaries for the Laravel modular monolith

### 2.1 Module structure

Recommended modules:

| Module | Responsibility | First needed |
| --- | --- | --- |
| `Identity` | Users, roles, permissions, authentication, staff access | Month 1 |
| `Admin` | Filament resources, dashboards, admin actions | Month 1 |
| `Settings` | Channels, shipping groups, business policies, system configuration | Month 1 |
| `Audit` | Audit logs and system/user action history | Month 1 |
| `Intake` | Mobile intake, staging items, photos before promotion, duplicate detection | Month 1–2 |
| `Media` | Image upload, variants, image processing, storage metadata | Month 2 |
| `Catalog` | Products, identifiers, categories, attributes, fitment | Month 1–4 |
| `Inventory` | Stock items, stock movements, reservations, availability | Month 4 |
| `Pricing` | Price suggestions, base price, channel price rules, exchange rates | Month 3–5 |
| `Readiness` | Product and channel readiness rules, blockers, warnings | Month 3–5 |
| `Integrations` | API clients, OAuth/token handling, rate limits, raw request/response logging | Month 3–5 |
| `WooBridge` | Woo import, Woo external refs, Woo create/update sync | Month 4 |
| `Channels` | Generic channel listings, channel adapters, publish jobs, channel errors | Month 5 |
| `Orders` | Imported orders, order items, order-driven stock changes | Later Month 5/6 or after |
| `Storefront` | Future Laravel shop frontend and checkout | Month 6 planning or later |
| `PartnerPortal` | Future partner/vendor submissions and restricted access | Later |

### 2.2 Boundary rules

- `Intake` may create `staging_items`, but only approved promotion logic should create `products`.
- `Catalog` owns products and product identifiers; channels store external listing data separately.
- `Inventory` owns stock availability; channel adapters may request stock sync but should not mutate stock directly without domain services.
- `Pricing` owns suggested/base/channel prices; publishing adapters should consume calculated prices, not invent prices.
- `Readiness` should be reusable by admin screens, queued jobs, and publishing actions.
- `Integrations` should contain API clients and token handling, but business decisions should stay in domain modules.
- `WooBridge` should be a migration bridge, not a permanent core dependency.
- `Channels` should define adapter contracts and durable listing/job/error records.
- `Orders` should eventually drive stock reservations/reductions from all sales channels.
- `Storefront` should use Product Hub catalog and stock directly, not duplicate product data.

### 2.3 Suggested code organization style

Keep one Laravel application, but organize by domain. A practical style:

- `app/Modules/Intake/...`
- `app/Modules/Catalog/...`
- `app/Modules/Pricing/...`
- `app/Modules/Inventory/...`
- `app/Modules/Readiness/...`
- `app/Modules/Channels/...`
- `app/Modules/Integrations/...`
- `app/Modules/WooBridge/...`

Alternative acceptable style:

- Standard Laravel folders with clear namespaces such as `App\Catalog`, `App\Intake`, `App\Channels`.

The exact style should be decided before coding. The important requirement is that marketplace API code does not leak into product models, admin resources, or controllers.

## 3. Suggested order of implementation

### 3.1 Build order

1. Confirm key technical decisions.
2. Set up Laravel application, database, Redis, Filament, auth, roles, and local/staging environment.
3. Create settings/configuration foundation for channels and shipping groups.
4. Create staging item model and manual admin screens.
5. Create media upload/storage model.
6. Build mobile PWA intake.
7. Add OEM normalization and duplicate detection.
8. Add internal categories and shipping group mapping.
9. Add read-only enrichment/price research integration prototypes.
10. Add price suggestions and acceptance/override flow.
11. Add product promotion from staging.
12. Add central product catalog screens.
13. Add stock items and stock movements.
14. Add product readiness checks.
15. Add Woo import.
16. Add Woo create/update sync with dry-run first.
17. Add channel listing model.
18. Add eBay DE/FR readiness research and checks.
19. Add publish job/error logging foundation.
20. Decide whether to run limited test publishing.
21. Stabilize operations and plan storefront.

### 3.2 Why this order

This order avoids building the riskiest marketplace publishing features before the core operational model exists. The team first proves:

- Staff can intake products through Product Hub.
- Product data quality can be improved before publishing.
- Products can be promoted from staging consistently.
- Woo can be updated safely while it remains the live storefront.
- Readiness can prevent bad publishing decisions.

Only after these are stable should eBay/Ovoko/Allegro publishing be expanded.

## 4. Database migration sequence

### 4.1 Migration batch 1 — foundation

Create foundational tables:

- `users`
- role/permission tables
- `audit_logs`
- `settings` or typed settings tables
- `channels`
- `shipping_groups`
- `warehouses` if using explicit warehouse records

Purpose:

- Let admin, permissions, audit, channel configuration, and shipping group setup exist before domain workflows depend on them.

### 4.2 Migration batch 2 — intake and media

Create intake tables:

- `staging_items`
- `staging_item_events`
- `media_files` or `product_images` with nullable `staging_item_id`
- optional `staging_item_duplicate_candidates`

Purpose:

- Support manual and mobile staging before central products are complete.

### 4.3 Migration batch 3 — catalog core

Create catalog tables:

- `product_categories`
- `attributes`
- `products`
- `product_identifiers`
- `product_attribute_values`
- `vehicles`
- `vehicle_fitments`

Purpose:

- Allow promotion from staging into central products.

### 4.4 Migration batch 4 — pricing and exchange rates

Create pricing tables:

- `price_suggestions`
- `channel_price_rules`
- `rounding_rules`
- `exchange_rates`
- optional `price_history`

Purpose:

- Store Allegro suggestions, manual decisions, NBP rates, and channel pricing previews.

### 4.5 Migration batch 5 — inventory

Create inventory tables:

- `stock_items`
- `stock_movements`
- optional `stock_reservations`

Purpose:

- Establish Product Hub stock state and traceable changes before broad channel sync.

### 4.6 Migration batch 6 — Woo bridge

Create Woo bridge tables or generic external reference tables:

- `external_references`
- `woo_sync_runs`
- `woo_sync_items`

Purpose:

- Store Woo product mappings, sync runs, dry-run results, errors, and imported source snapshots.

### 4.7 Migration batch 7 — readiness

Create readiness tables if results are persisted separately:

- `readiness_results`
- `readiness_rule_results`

Purpose:

- Store product/channel readiness results, blocker details, warnings, and rule versions.

Alternative:

- Store latest readiness status on products/listings and detailed JSON snapshots in a results table.

### 4.8 Migration batch 8 — channel listings and publish logs

Create channel tables:

- `channel_listings`
- `channel_errors`
- `publish_jobs`
- `channel_category_mappings`
- `channel_required_aspects`
- `business_policies`

Purpose:

- Prepare eBay DE/FR and future channels without requiring production publish to be active.

### 4.9 Migration batch 9 — orders

Create order tables:

- `orders`
- `order_items`
- optional `order_events`

Purpose:

- Add order import and order-driven stock changes after product, listing, and stock foundations exist.

### 4.10 Migration batch 10 — storefront and partner portal later

Later tables:

- storefront carts/checkouts/payments if building native checkout.
- partner/vendor organizations.
- partner submissions.
- partner inventory visibility.

Purpose:

- Avoid polluting the first MVP with features not needed to replace intake/staging/catalog/Woo sync.

## 5. First MVP scope

### 5.1 MVP goal

The first MVP should prove that Product Hub can replace Gmail-based intake and become the internal staging/catalog control layer while WooCommerce remains the live storefront.

### 5.2 MVP included scope

Included:

- Laravel application foundation.
- Filament admin.
- Users, roles, permissions.
- Mobile PWA intake.
- Staging items.
- Multiple image uploads.
- OEM/part number entry and normalization.
- Duplicate candidate detection.
- Location/bin and condition capture.
- Internal categories.
- Shipping groups `shipping_30`, `shipping_50`, `shipping_130`.
- Product promotion from staging.
- Central product catalog.
- Product identifiers/OEMs.
- Product images.
- Basic stock item with location/bin and quantity.
- Price suggestion storage and manual price setting.
- Basic readiness checks.
- Woo import and Woo product create/update sync.
- Audit logs.
- Error/log screens for intake and Woo sync.

### 5.3 MVP excluded scope

Excluded from first MVP:

- Full eBay DE/FR production publishing.
- Full Allegro production publishing.
- Full Ovoko production publishing.
- Native Laravel storefront checkout.
- Partner/vendor portal.
- Advanced offline mobile sync.
- Complex OCR automation.
- Automated translation at scale.
- Fully automated pricing without review.
- Complex warehouse management.
- Returns/refunds automation.
- Advanced analytics/business intelligence.

### 5.4 MVP success criteria

The MVP is successful if:

- Staff can create staging items from phones.
- Admin can review staging items and promote them to products.
- Product Hub stores clean OEM, images, category, price, stock, and source history.
- Product readiness explains missing data.
- Product Hub can create/update Woo products safely.
- Woo remains live during the transition.
- No marketplace publishing is accidentally triggered.

## 6. What should be postponed until later

### 6.1 Postpone until after MVP stability

Postpone:

- Broad production eBay DE/FR publishing.
- Automatic marketplace revise/update rules.
- Allegro publishing.
- Ovoko publishing and stock sync.
- Partner/vendor portal.
- Full Laravel storefront and checkout.
- Advanced OCR and offline-first PWA behavior.
- Machine-learning category prediction.
- Complex repricing automation.
- Multi-warehouse optimization.
- Native mobile app.

### 6.2 Allowed technical validation before full feature delivery

Some later features may need narrow validation earlier:

- eBay sandbox publishing of one or two test products.
- Allegro API price research proof of concept.
- Ovoko read-only enrichment proof of concept.
- NBP exchange-rate fetch proof of concept.
- Woo API create/update proof of concept.

These validations should not become production workflows until readiness, logging, duplicate guards, permissions, and rollback procedures exist.

## 7. Key technical decisions before coding

### 7.1 Application architecture

Decide:

- Exact Laravel version.
- Module organization style.
- Whether to use a package such as `nwidart/laravel-modules` or simple application namespaces.
- Coding standards and test strategy.
- CI pipeline requirements.

Recommendation:

- Use plain Laravel with clear namespaces/modules unless there is a strong team preference for a module package.

### 7.2 Database

Decide:

- PostgreSQL vs MySQL.
- Local/staging/production database hosting approach.
- Backup and restore strategy.
- UUID vs integer IDs.
- JSONB usage policy if PostgreSQL is chosen.

Recommendation:

- Use PostgreSQL and regular integer or UUID primary keys based on team preference. Keep external IDs separate from internal IDs.

### 7.3 Admin panel

Decide:

- Filament version.
- Role/permission package.
- Admin theme/customization approach.
- Which actions require confirmation or approval.

Recommendation:

- Use Filament and Spatie Laravel Permission or equivalent.

### 7.4 Mobile PWA

Decide:

- Blade/Livewire, Inertia/Vue, or another frontend approach for mobile intake.
- Upload strategy for large phone images.
- Direct-to-object-storage upload vs application-mediated upload.
- Image compression/resizing strategy.
- Minimum browser/device support.

Recommendation:

- Keep MVP mobile intake simple and reliable. Avoid complex offline-first behavior until the online workflow works well.

### 7.5 Image storage

Decide:

- Object storage provider.
- Public/private bucket strategy.
- CDN usage.
- Image variant sizes.
- Retention policy for originals.

Recommendation:

- Store originals and variants in S3-compatible object storage and keep metadata in the database.

### 7.6 Queues

Decide:

- Redis hosting.
- Queue names and priorities.
- Horizon usage.
- Retry/backoff strategy.
- Failed-job alerting.

Recommendation:

- Use Redis + Horizon and separate high-risk publishing/sync jobs from slower enrichment/media jobs.

### 7.7 Search

Decide:

- Whether Meilisearch is needed in MVP or can wait until catalog size makes database search uncomfortable.
- Search indexing rules for OEM, SKU, title, external IDs, and vehicle data.

Recommendation:

- Start with database search if catalog size is modest; add Meilisearch when search quality/performance becomes a real need.

### 7.8 Woo migration ownership

Decide:

- Which data Product Hub imports from Woo in Phase 1.
- Which data Product Hub is allowed to write back to Woo in Phase 2.
- How Product Hub marks Woo products it created or controls.
- Whether Woo stock remains authoritative during early testing or Product Hub starts controlling stock for new products.

Recommendation:

- Import broadly, write narrowly, and expand write scope only after dry-run comparisons are reviewed.

### 7.9 Marketplace credentials and environments

Decide:

- Who owns eBay, Allegro, Ovoko, Woo, and NBP credentials.
- Sandbox/test account availability.
- OAuth refresh handling.
- Credential rotation process.
- Who can trigger live publish/sync actions.

Recommendation:

- Store credentials encrypted, restrict access, and separate sandbox/test credentials from production credentials.

### 7.10 SKU and identifier policy

Decide:

- Internal SKU format.
- OEM normalization rules.
- Duplicate detection thresholds.
- Whether multiple products can share an OEM.
- How one-off used parts are represented when quantity is usually one.

Recommendation:

- Allow multiple products to share an OEM, but flag duplicates by OEM + category + vehicle + active stock/listing status.

## 8. External API research tasks

### 8.1 WooCommerce API research

Research tasks:

- Confirm API authentication method.
- Confirm product create/update fields required by current Woo store.
- Confirm image upload/update approach.
- Confirm category assignment behavior.
- Confirm stock quantity and stock status fields.
- Confirm custom metadata fields needed to store Product Hub IDs.
- Confirm how existing products can be imported efficiently.
- Confirm pagination and rate limits.
- Confirm variation/product type handling if relevant.
- Confirm error responses and retry behavior.
- Confirm whether Woo webhooks should be used during migration.

Deliverables:

- Woo API capability matrix.
- Field mapping document: Product Hub product to Woo product.
- Dry-run sync plan.
- Rollback plan for accidental Woo updates.
- Test product checklist.

### 8.2 eBay DE/FR API research

Research tasks:

- Confirm eBay API family to use for inventory, offers, fulfillment policies, payment policies, return policies, categories, aspects, and orders.
- Confirm marketplace IDs for DE and FR.
- Confirm sandbox support and limitations.
- Confirm OAuth flow and required scopes.
- Confirm listing creation workflow.
- Confirm category metadata and required aspects retrieval.
- Confirm business policy requirements.
- Confirm SKU uniqueness requirements.
- Confirm stock update/revise workflow.
- Confirm order import workflow and webhook/notification options.
- Confirm image requirements and hosting approach.
- Confirm duplicate listing risks and safeguards.
- Confirm FR content, locale, currency, and translation requirements.

Deliverables:

- eBay DE/FR capability matrix.
- eBay category/aspect mapping approach.
- Business policy configuration plan.
- eBay readiness rule list.
- Sandbox publishing test plan.
- Production publishing gate checklist.

### 8.3 Allegro API research

Research tasks:

- Confirm authentication and application registration requirements.
- Confirm search endpoints usable for OEM price research.
- Confirm whether completed/sold offer data is available or only active listings.
- Confirm category and parameter data access.
- Confirm rate limits.
- Confirm language/currency assumptions.
- Confirm filtering options for condition, category, seller type, and title/OEM matching.
- Confirm if API terms allow storing summarized pricing results.
- Confirm later publishing requirements and whether it should be treated as a separate phase.

Deliverables:

- Allegro price research feasibility report.
- Price sample collection strategy.
- Outlier filtering proposal.
- Confidence scoring proposal.
- Later publishing feasibility notes.

### 8.4 Ovoko API research

Research tasks:

- Confirm official API availability and access process.
- Confirm authentication method.
- Confirm enrichment by OEM/part number capabilities.
- Confirm fields returned for parts, vehicles, fitment, category, and images if any.
- Confirm rate limits and usage restrictions.
- Confirm whether publishing API exists and what data it requires.
- Confirm stock sync capability.
- Confirm order import capability if relevant.
- Confirm whether read-only enrichment can be used without changing Ovoko data.

Deliverables:

- Ovoko API capability matrix.
- Read-only enrichment mapping plan.
- Enrichment confidence rules.
- Publishing/stock sync feasibility report for later phases.
- Explicit no-scraping policy confirmation.

### 8.5 NBP exchange rate API research

Research tasks:

- Confirm endpoint for PLN to EUR conversion.
- Confirm update frequency and availability.
- Confirm historical rate access.
- Confirm error behavior and fallback strategy.
- Confirm whether to use table A, B, or another endpoint for the relevant rate.
- Confirm caching and storage policy.

Deliverables:

- NBP rate fetch plan.
- Exchange-rate storage design.
- Fallback/manual override plan.
- Pricing calculation examples for eBay FR.

## 9. Risks and mitigation plan

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Scope creep into full marketplace replacement too early | Delays core intake/catalog MVP | Keep Month 1–4 focused on intake, staging, catalog, readiness, and Woo sync |
| Woo data quality is inconsistent | Bad imports and wrong mappings | Import into reviewable Product Hub records, show data quality warnings, avoid overwriting Woo initially |
| Mobile photo uploads are unreliable | Staff cannot use intake workflow | Add upload progress, retries, image compression, and clear errors; test on real phones |
| Duplicate detection is too strict or too weak | Missed duplicates or false alarms | Use normalized OEM plus category/vehicle/listing status, and require human review |
| External APIs are unavailable or limited | Planned enrichment/pricing/publishing blocked | Research APIs early, build adapters, store capability matrices, keep manual fallback |
| eBay publishing complexity is underestimated | Marketplace rollout risk | Treat eBay publishing as Month 5 validation, not core MVP; require readiness and duplicate guards |
| One-off stock oversells across channels | Financial/customer service risk | Keep Woo as primary storefront early, add central stock movements, later add high-priority stock sync |
| Credentials are mishandled | Security risk | Encrypt tokens, restrict permissions, audit changes, separate sandbox and production credentials |
| Queue failures hide operational problems | Silent sync/publish issues | Use Horizon, Error Center, failed-job alerts, durable sync/publish records |
| Team becomes dependent on Product Hub before it is stable | Business disruption | Run side-by-side with Woo first, define rollback procedures, use dry-run sync |
| Readiness rules become hardcoded and hard to change | Future maintenance burden | Store configurable mappings, rule versions, and clear blockers/warnings |
| Storefront work starts too soon | Core system quality drops | Keep storefront to Month 6 planning/MVP only after intake/catalog/Woo are stable |

## 10. Definition of done for each phase

### Phase 1 done — Foundation side system

Done when:

- Product Hub runs in local and staging.
- Admin login and roles work.
- Staging items can be created manually.
- Basic settings/channels/shipping groups exist.
- Audit logging works for key changes.
- No production system depends on Product Hub yet.

### Phase 2 done — Mobile intake MVP

Done when:

- Staff can create staging items from phones.
- Photos, OEM, condition, location/bin, and notes are captured.
- Upload failures are visible and recoverable.
- Duplicate detection runs on normalized OEM.
- Admin can review and update mobile-created staging items.

### Phase 3 done — Enrichment, pricing, mapping

Done when:

- Ovoko, Allegro, and NBP API feasibility is documented.
- Available read-only enrichment and price research jobs work in a controlled way.
- Category and shipping group suggestions can be reviewed.
- Price suggestions are stored with confidence and source.
- Readiness blockers exist for missing core staging data.

### Phase 4 done — Product catalog and Woo bridge

Done when:

- Staging item can be promoted to central product.
- Product catalog stores identifiers, images, category, price, and stock.
- Product readiness checks run and are visible.
- Woo import works without overwriting Woo.
- Product Hub can create/update Woo products in approved mode.
- Woo sync errors are logged and retryable.

### Phase 5 done — Channel readiness and eBay validation

Done when:

- eBay DE/FR API research is complete.
- Channel listings and publish job records exist.
- eBay DE/FR readiness checks show blockers and warnings.
- Category/aspect/business policy gaps are visible.
- Duplicate guards exist.
- Any publishing test is sandbox/limited/approved and logged.
- Broad production marketplace publishing remains disabled unless explicitly approved.

### Phase 6 done — Stabilized operational hub

Done when:

- The team can run intake to Woo sync for real products.
- Operational dashboards and Error Center are usable.
- Backup and restore procedure has been tested.
- Security and credential handling have been reviewed.
- Migration runbook and rollback plan exist.
- Storefront path for the next phase is decided.
- The next roadmap is approved.


## 11. Admin UX/UI direction for daily operations

### 11.1 UX inspiration and boundary

GPS Product Hub should use the same kind of operational simplicity that makes warehouse-focused admin panels effective: fast navigation, clear work queues, obvious actions, and minimal visual noise. The Ovoko admin style can be used as a reference for this operational feeling, but Product Hub must not copy Ovoko visually, technically, or structurally. The goal is not decoration; the goal is a calm, fast, readable interface that non-technical warehouse and admin staff can use for many hours every day.

The admin UX should be designed around daily work questions:

- What needs attention now?
- Which parts are blocked and why?
- Which products are ready for the next step?
- What can staff do in one or two clicks?
- Which errors require human action?

### 11.2 Navigation model

Use a left sidebar with clear operational modules. Suggested first-level navigation:

- Dashboard
- Add part / Intake
- Staging items
- Product Command Center
- Products
- Images
- Pricing
- Stock
- Readiness
- Publish Center
- Orders
- Error Center
- Imports / Exports
- Settings
- Users / Roles

The sidebar should be stable and predictable. Staff should not need to remember where common actions are located. Module labels should be short, practical, and translatable into simple Polish labels for staff users.

### 11.3 Top bar and global search

The top bar should include global search as a primary workflow tool, not a secondary feature. Search should support:

- Internal SKU.
- OEM / normalized OEM.
- Product title.
- Staging item ID.
- Location/bin.
- External Woo/eBay/Allegro/Ovoko IDs where available.
- Order number.

Search results should be grouped by type, for example products, staging items, orders, and channel listings. A warehouse user should be able to scan or type an OEM and quickly reach the correct staging item or product.

### 11.4 Action-focused dashboard

The dashboard should focus on operational action, not only analytics. It should answer what staff should do next.

Recommended dashboard elements:

- Large quick-action buttons.
- Problem counters.
- Work queues that need review.
- Recent failed jobs or sync errors.
- Recently captured staging items.
- Products ready for next step.

Quick-action buttons should be large, visible, and mobile-friendly:

- Add part.
- Scan.
- Add price.
- Create staging item.
- Publish ready products.

These actions should route users directly into the relevant workflow with as few intermediate screens as possible.

### 11.5 Operational counters

Visible counters should be shown on the dashboard and, where useful, in sidebar badges. Counters should link directly to filtered lists. Required counters:

- Parts without price.
- Staging items needing review.
- Duplicate candidates.
- Products missing images.
- Products missing OEM.
- Products ready to publish.
- Failed publishing jobs.
- Orders needing action.

Additional useful counters:

- Products missing category.
- Products missing shipping group.
- Products with stock sync errors.
- Products blocked by eBay required aspects.
- Staging items with failed enrichment.
- Woo sync failures.

### 11.6 Product Command Center

The Product Command Center should be the main operational workspace, similar in spirit to warehouse/product admin systems where staff can see what needs attention in one place. It should not be a decorative dashboard; it should be a practical work queue.

Recommended capabilities:

- Search and filter by OEM, SKU, title, category, status, stock, readiness, and channel state.
- Saved filters for common problems.
- Status badges for draft, blocked, ready, published, sold, archived, and error states.
- Blocking reasons shown directly in the table or expandable row.
- Bulk actions only where safe and permission-controlled.
- Direct row actions such as add price, add image, resolve duplicate, assign category, recalculate readiness, and open product.
- Clear distinction between product readiness and channel readiness.

The goal is that a staff user can open one screen and immediately know which products require photos, OEM review, pricing, category mapping, readiness fixes, or publishing attention.

### 11.7 Mobile and warehouse-friendly layout

The UI must work well for phones, tablets, and warehouse workstations. Mobile intake should be optimized separately from dense admin tables.

Requirements:

- Large touch targets for intake actions.
- Camera/photo workflow that is easy to use with one hand.
- Short forms for warehouse capture.
- Clear upload progress and retry states.
- Minimal typing where dropdowns or scan input are better.
- Responsive layouts for staging review and product lookup.
- Fast loading lists with practical filters.

Offline-first behavior is not required for the MVP, but the UI should avoid losing user-entered form data during short network interruptions where reasonably possible.

### 11.8 Polish-language staff labels

Staff-facing labels should support simple Polish language from the beginning, even if internal technical names remain English. Labels should be practical and short.

Examples:

| English concept | Suggested Polish staff label |
| --- | --- |
| Add part | Dodaj część |
| Scan | Skanuj |
| Add price | Dodaj cenę |
| Create staging item | Utwórz pozycję roboczą |
| Needs review | Do sprawdzenia |
| Duplicate candidate | Możliwy duplikat |
| Missing images | Brak zdjęć |
| Missing OEM | Brak OEM |
| Ready to publish | Gotowe do publikacji |
| Blocked | Zablokowane |
| Published | Opublikowane |
| Failed jobs | Błędy zadań |

The implementation should avoid exposing overly technical labels such as queue names, adapter names, raw enum values, or API terms to warehouse users unless they are in an advanced/admin-only detail view.

### 11.9 Visual style and color system

The visual style should be calm, professional, and comfortable for long daily work. It should prioritize readability and low eye fatigue.

Preferred style:

- White or very light background.
- Black or near-black text.
- Dark navy as the main accent color.
- Dark navy for primary buttons and important actions.
- Strong table and form readability.
- High contrast, but calm visual design.
- Minimal visual noise.

Avoid:

- Bright aggressive colors as the main UI theme.
- Decorative gradients or unnecessary animation.
- Low-contrast gray text for important data.
- Dense screens without spacing or hierarchy.
- Using color as the only way to communicate status.

Color should be used only where it has meaning:

- Success.
- Warning.
- Error.
- Ready.
- Blocked.
- Draft.
- Published.

Status colors should be subtle and readable, not flashy. Every status should also have text labels and, where helpful, icons or badges so that meaning does not rely on color alone.

### 11.10 Minimal-click workflow expectations

Common tasks should be achievable with minimal clicks:

- Add a new part from dashboard or sidebar.
- Search OEM and open matching product/staging item.
- Add or change price from a product list.
- Add missing image from product or staging detail.
- Review duplicate candidate.
- Assign category/shipping group.
- See why a product is blocked.
- Retry a failed Woo sync or publish validation job.

Each main screen should have one obvious primary action. Secondary actions should be available but not visually compete with the main workflow.

### 11.11 UX definition of done

The admin UX direction is done for the MVP when:

- Staff can reach Add part, Scan, Add price, Create staging item, and key review queues from the dashboard or sidebar.
- Global search can find products and staging items by SKU/OEM.
- Operational counters link to filtered lists.
- Product Command Center shows readiness status and blocking reasons.
- Mobile intake works comfortably on a phone.
- Polish labels are available for staff-facing workflows.
- The default theme uses a light background, near-black text, and dark navy primary actions.
- Status colors are subtle, readable, and paired with text labels.
- Common daily workflows avoid unnecessary intermediate screens.

## 12. Practical first backlog

### 12.1 Week 1–2 backlog

- Confirm PostgreSQL/MySQL decision.
- Confirm Filament and role/permission approach.
- Confirm module organization style.
- Confirm object storage provider for staging/production.
- Confirm queue/Horizon approach.
- Create initial decision log.
- Define SKU and OEM normalization draft.
- Define initial role list.
- Define staging statuses and transitions.

### 12.2 Week 3–4 backlog

- Create initial Laravel foundation.
- Create admin login and roles.
- Create staging item model and admin list/detail.
- Create shipping group settings.
- Create channel settings placeholders.
- Create audit log foundation.
- Prepare local/staging deployment notes.

### 12.3 First validation checklist

Before expanding implementation, validate:

- Can non-developers understand the staging status names?
- Can staff capture the minimum product data in less than two minutes?
- Are duplicate warnings helpful rather than blocking too much?
- Can the team recover from failed image uploads?
- Does Woo dry-run output clearly show what would change?
- Are readiness blockers actionable?

## 13. Recommended decision log template

Every major implementation decision should be recorded with:

- Decision date.
- Decision owner.
- Options considered.
- Chosen option.
- Reason.
- Risks/tradeoffs.
- Revisit date or condition.

Initial decisions to record:

- Database choice.
- Module organization.
- Admin framework.
- Queue system.
- Object storage.
- Search engine timing.
- Mobile frontend approach.
- Woo sync ownership rules.
- API credential handling.
- Marketplace publishing gate policy.

## 14. Safe implementation process for Codex tickets

Codex should build GPS Product Hub through small, reviewable implementation tickets. The implementation process must follow:

- `docs/gps-product-hub-blueprint.md`
- `docs/gps-product-hub-implementation-plan.md`
- `docs/gps-product-hub-mvp-specification.md`

### 14.1 Ticket rules

Rules for implementation tickets:

- Do not build the whole system at once.
- Do not implement modules that are not part of the current ticket.
- Keep changes small and easy to review.
- Prefer clean Laravel conventions over clever abstractions.
- Always run relevant tests/checks before finishing.
- Do not introduce marketplace publishing until the MVP foundation is stable.
- Do not add external API writes without explicit approval.
- Use feature flags or disabled-by-default behavior for risky integrations.
- Keep production safety in mind from the beginning.

### 14.2 Required implementation report

Each implementation step should report:

- What was changed.
- Why it was changed.
- Files created or modified.
- Migrations added.
- Tests added, or why tests were not added.
- Commands run.
- Risky behavior that is disabled by default.
- Follow-up work or known limitations.

### 14.3 First implementation ticket

The first implementation after the MVP specification should be only the Laravel/Filament foundation and basic auth/roles/admin skeleton unless explicitly requested otherwise.

That first ticket should not include:

- Staging workflow implementation.
- Product catalog implementation.
- WooCommerce sync.
- eBay/Ovoko/Allegro integration.
- Marketplace publishing.
- External API writes.

### 14.4 Production safety gates

Risky behavior should be gated from the start:

- Marketplace publishing must be unavailable by default.
- External API writes must be unavailable without explicit approval.
- Bulk operations must require permission and confirmation.
- Integration credentials must not be exposed in normal admin views.
- Dry-run mode should come before live write mode where practical.
- Failed external jobs must be logged and visible before automation is expanded.

## 15. Summary execution stance

The first six months should not be treated as a race to replace every external system. The correct sequence is:

1. Build Product Hub as the internal operational foundation.
2. Replace unstructured Gmail intake with mobile staging.
3. Create clean product records with OEMs, photos, categories, price, and stock.
4. Add readiness so the system can explain what is missing.
5. Sync safely to Woo while Woo remains live.
6. Research and validate marketplace APIs.
7. Only then expand into direct eBay/Ovoko/Allegro publishing.

This gives the team a controlled migration path and avoids creating another fragile plugin chain in a different technology stack.
