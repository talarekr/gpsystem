# GPS Product Hub — Laravel Central Commerce and Marketplace System

## Executive architecture summary

GPS Product Hub should be built as a Laravel modular monolith: one deployable application, one primary database, clear internal modules, and explicit integration adapters for WooCommerce, eBay DE, eBay FR, Allegro, Ovoko, and future partner/vendor channels. The key architectural principle is that Product Hub becomes the source of truth for products, stock, readiness, publishing decisions, marketplace state, and operational audit history. External systems should be treated as channels that can be synchronized, queried, or published to, but not as the master system.

This is intentionally not a microservice design for the first six months. A modular monolith gives a small team faster delivery, simpler debugging, simpler deployment, safer migrations, and better data consistency. The codebase can still be organized into modules with boundaries that allow later extraction if a specific part grows large enough.

Recommended foundation:

- **Application:** Laravel 11/12 style application, organized as a modular monolith.
- **Admin panel:** Filament as the main admin framework.
- **Database:** PostgreSQL preferred; MySQL acceptable if team operations strongly prefer it.
- **Queues:** Redis queues with Laravel Horizon.
- **Cache:** Redis.
- **Search:** Meilisearch for MVP; OpenSearch/Elasticsearch only if search requirements become more complex.
- **Images/files:** S3-compatible object storage for production, local/S3-compatible storage for staging and development.
- **Deployment:** VPS/cloud/datacenter production, not local physical server production.
- **Monitoring:** Laravel logs, Horizon, uptime monitoring, error tracking, database backups, object storage backups, and audit logs.

## 1. Recommended architecture

### 1.1 Modular monolith recommendation

Use a **modular Laravel monolith** rather than microservices.

Recommended module boundary style:

- `Catalog` for products, identifiers, categories, attributes, images, and fitment.
- `Intake` for mobile capture, staging items, enrichment preparation, and duplicate detection.
- `Pricing` for price suggestions, channel price rules, exchange rates, and manual overrides.
- `Inventory` for stock items, stock movements, reservations, and stock synchronization.
- `Channels` for marketplace adapters and channel listings.
- `Orders` for imported and future native storefront orders.
- `Readiness` for reusable validation rules and per-channel blocking reasons.
- `Admin` for Filament resources, dashboards, actions, and operational screens.
- `Integrations` for API clients, credentials, webhooks, importers, and external API rate limits.
- `Audit` for event history and user action logging.
- `Identity` for users, roles, permissions, and future partner/vendor access.

Why this is preferred:

- One deployment is easier for a small team.
- One database transaction can cover product, stock, readiness, and listing state updates.
- Marketplace integrations can be slow, unreliable, and stateful; queues and explicit job state are enough at this stage.
- Modules can still be kept independent through services, actions, contracts, policies, and event boundaries.

Avoid:

- Direct marketplace logic inside admin controllers.
- Treating WooCommerce categories, product IDs, or plugin state as the internal model.
- Browser scraping as a core integration strategy.
- Hidden automatic publishing without readiness checks and audit logs.

### 1.2 Database choice

**Recommendation: PostgreSQL.**

Reasons:

- Strong data integrity and transaction behavior.
- Excellent JSONB support for storing raw API payloads, enrichment snapshots, channel-specific metadata, and readiness result details.
- Good indexing options for normalized OEM numbers, JSONB fields, text search helpers, and reporting.
- Better long-term fit for a central system with rich relational data and integration history.

MySQL is still acceptable if operations already have strong MySQL experience, but PostgreSQL is the stronger default for this project.

### 1.3 Queue system

Use **Redis queues + Laravel Horizon**.

Queues should handle:

- Image processing.
- OCR attempts.
- Duplicate detection.
- Ovoko enrichment calls.
- Allegro price research.
- WooCommerce sync.
- eBay publish/update/revise calls.
- Order import.
- Stock synchronization.
- Readiness recalculation.
- Retryable integration failures.
- Scheduled reports.

Queue design should include:

- Separate queues by risk and latency: `default`, `images`, `enrichment`, `pricing`, `publishing`, `sync`, `orders`, `webhooks`, `reports`.
- Explicit job records for publish attempts, not only Laravel queue rows.
- Idempotency keys for publish/sync operations.
- Rate limiters per external API.
- Dead-letter/error review screens in the admin panel.

### 1.4 File and image storage

Use S3-compatible object storage for production:

- AWS S3, Cloudflare R2, Backblaze B2, Wasabi, or a datacenter S3-compatible service.
- Store originals and generated variants separately.
- Keep image metadata in the database.
- Use CDN in front of public image variants when traffic grows.

Image variants:

- Original upload.
- Admin preview.
- Marketplace-safe large image.
- Thumbnail.
- Optional watermark/no-watermark variants if needed later.

Never store production images only on the web server disk.

### 1.5 Search engine

Use **Meilisearch** for MVP search:

- Fast product search by OEM, normalized OEM, title, SKU, category, vehicle, and channel listing ID.
- Easy Laravel Scout integration.
- Simple operations compared with OpenSearch.

Upgrade to OpenSearch/Elasticsearch later only if advanced analytics, complex faceting, heavy scale, or log search requirements justify it.

### 1.6 Admin panel framework

Use **Filament** for the admin panel.

Reasons:

- Strong Laravel-native resource system.
- Fast CRUD creation for products, staging items, channel listings, orders, and settings.
- Good tables, filters, actions, relation managers, dashboards, widgets, and role-based controls.
- Can coexist with a custom PWA/mobile interface for intake.

Use Filament for back-office workflows, not necessarily for every mobile intake screen. Mobile intake should be optimized as a dedicated PWA route/UI.

### 1.7 Deployment approach

Recommended deployment approach:

- Dockerized application or a managed Laravel VPS stack.
- Separate production and staging environments.
- Git-based deployment with CI checks.
- Zero/minimal-downtime deploys where possible.
- Separate queue workers and scheduler process.
- No production hosting on a local physical server.

Production components:

- Web app container/server.
- Queue worker process pool.
- Scheduler worker.
- PostgreSQL managed instance or well-backed VPS database.
- Redis instance.
- Object storage.
- Meilisearch service.
- Monitoring/error tracking.

### 1.8 Backup and monitoring approach

Backups:

- Daily database backups, with point-in-time recovery if available.
- Encrypted offsite backup copies.
- Object storage lifecycle/versioning for product images.
- Configuration and secret backup procedure.
- Regular restore tests, not only backup creation.

Monitoring:

- Uptime checks for admin and storefront endpoints.
- Queue health via Horizon.
- Failed job alerts.
- Error tracking with Sentry, Flare, or equivalent.
- Disk, CPU, memory, Redis, database storage, and queue latency alerts.
- Integration-specific dashboards for eBay, Woo, Ovoko, Allegro, and order sync.

## 2. Core modules

### 2.1 Product catalog

Responsibilities:

- Central product records.
- Product lifecycle status.
- Base title and description.
- Internal SKU.
- Product condition.
- Source history.
- Relationship to stock, identifiers, images, categories, attributes, fitment, and channel listings.

Key rules:

- Product Hub owns the canonical product record.
- Marketplace data should be attached as channel-specific listing records.
- WooCommerce product IDs should be external references, not internal primary IDs.

### 2.2 Product identifiers / OEM numbers

Responsibilities:

- Store raw OEM/part numbers.
- Store normalized OEM values.
- Support multiple identifiers per product.
- Mark one identifier as primary where appropriate.
- Track identifier source: manual, OCR, Ovoko, Woo import, partner import, API.

Normalization examples:

- Remove spaces and punctuation.
- Uppercase letters.
- Preserve raw value for display and audit.
- Store normalized value for duplicate checks and matching.

### 2.3 Vehicle fitment

Responsibilities:

- Store vehicle make, model, generation, year range, engine, fuel type, body type, gearbox, and other fitment attributes.
- Attach products to one or more compatible vehicles.
- Support imported fitment and manually reviewed fitment.
- Support channel-specific fitment export if marketplaces require structured compatibility.

### 2.4 Product images

Responsibilities:

- Store original and processed images.
- Sort order and primary image flag.
- Per-channel inclusion/exclusion if needed.
- Processing status.
- Image quality/readiness checks.

### 2.5 Categories

Responsibilities:

- Internal category hierarchy independent of Woo/eBay/Allegro/Ovoko categories.
- Category-level default attributes.
- Category-level default shipping group.
- Per-channel category mappings.
- Required aspect definitions for marketplaces.

### 2.6 Attributes/parameters

Responsibilities:

- Flexible product facts such as side, color, material, engine code, position, part type, condition details, and manufacturer.
- Internal attribute definitions.
- Channel-specific mapping to eBay aspects, Allegro parameters, Woo attributes, and future storefront filters.

### 2.7 Pricing

Responsibilities:

- Central base price.
- Price source and confidence.
- Allegro price suggestions.
- Manual override tracking.
- Channel price rules.
- Currency conversion.
- Rounding.
- Price history.

### 2.8 Stock

Responsibilities:

- Quantity, usually one for used parts.
- Warehouse/location/bin.
- Stock status.
- Reservations during order processing.
- Stock movements and audit trail.
- Channel quantity synchronization.

### 2.9 Import/staging queue

Responsibilities:

- All new items enter staging before becoming products.
- Support mobile, Gmail legacy, manual, Woo import, API, and partner sources.
- Track enrichment, pricing, category suggestions, readiness, duplicate checks, and blocking reasons.

### 2.10 Mobile intake/PWA

Responsibilities:

- Camera capture.
- Multiple photos.
- OEM scan/OCR attempt if feasible.
- Manual OEM correction.
- Location/bin capture.
- Condition and notes.
- Upload progress.
- Save as staging item.
- Basic duplicate warning before or after save.

### 2.11 Readiness checks

Responsibilities:

- Determine if staging item can become product.
- Determine if product can publish to each channel.
- Produce blocking reasons, warnings, and suggested fixes.
- Recalculate after relevant changes.

### 2.12 Marketplace listings

Responsibilities:

- Store per-channel listing status and external IDs.
- Track category, policy, price, quantity, URL, and errors.
- Distinguish draft, ready, publishing, published, sync error, ended, archived.

### 2.13 Marketplace publishing

Responsibilities:

- Build publish payloads.
- Validate channel readiness.
- Queue publish jobs.
- Store publish logs and raw request/response metadata.
- Use duplicate guards.
- Support retry and manual intervention.

### 2.14 Orders

Responsibilities:

- Import orders from Woo/eBay/Allegro/future storefront.
- Map order items to products and stock items.
- Reserve/reduce stock.
- Trigger channel stock updates.
- Store customer/shipping data according to privacy rules.

### 2.15 Stock synchronization

Responsibilities:

- Push quantity changes to channels.
- Import stock-related external changes where unavoidable.
- Prevent overselling.
- Prioritize stock updates after orders.

### 2.16 Audit logs

Responsibilities:

- Record who changed what and when.
- Record publishing approvals.
- Record price changes.
- Record external API actions.
- Keep raw enough context for troubleshooting.

### 2.17 Users/roles

Responsibilities:

- Admin users.
- Staff users.
- Intake-only users.
- Pricing users.
- Publishing approvers.
- Future partner/vendor users.
- Permissions by action and module.

### 2.18 Future partner/vendor portal

Responsibilities:

- Partner product submissions.
- Partner inventory visibility.
- Approval workflow before products or listings affect company marketplaces.
- Restricted roles and no direct external marketplace publishing.

## 3. Mobile product intake instead of Gmail as the future workflow

### 3.1 Target workflow

The future intake flow should be:

1. Staff opens the mobile PWA on a phone.
2. Staff scans, OCRs, or manually enters OEM/part number.
3. Staff takes multiple product photos.
4. Staff enters warehouse/bin, condition, and notes.
5. Product Hub saves a staging item.
6. System normalizes OEM and runs duplicate detection.
7. System enriches data from Ovoko where available.
8. System researches price through Allegro where appropriate.
9. System suggests category, shipping group, and required attributes.
10. Staff reviews readiness and blocking reasons.
11. Staff creates central product.
12. Staff approves publishing to selected channels.

### 3.2 Mobile PWA features

MVP features:

- Login with staff role.
- Camera capture using browser media/file APIs.
- Multiple photos per staging item.
- Manual OEM entry.
- Normalized OEM preview.
- Condition dropdown.
- Warehouse/bin field.
- Notes field.
- Upload progress.
- Save draft/staging item.
- Duplicate warning after save.
- Retry failed image uploads.

Post-MVP features:

- OCR/scan for OEM or part number.
- Barcode/QR support if internal labels are introduced.
- Offline-tolerant draft storage using IndexedDB.
- Background sync where browser support is reliable.
- Guided photo checklist by category.
- Intake performance dashboard per staff user.

### 3.3 Offline stance

Offline support should be **tolerant but not complex in MVP**. The MVP should not depend on perfect offline synchronization. A practical path:

- MVP: require connection, but preserve unsent form state locally during short interruptions.
- Later: store draft staging items and photos locally, then upload when online.
- Avoid complicated conflict resolution until there is a proven operational need.

## 4. Product staging flow

### 4.1 Required stages

Use these staging statuses:

- `captured`
- `needs_oem_review`
- `duplicate_candidate`
- `enrichment_pending`
- `enriched`
- `price_suggested`
- `category_mapped`
- `ready_to_product`
- `product_created`
- `ready_to_publish`
- `published`
- `error`
- `archived`

### 4.2 Staging item fields

A staging item should store:

- Source type: `mobile`, `gmail_legacy`, `manual`, `api`, `woo_import`, `partner`.
- Source reference ID.
- Raw OEM/part number.
- Normalized OEM/part number.
- Photos.
- Notes.
- Warehouse/bin/location.
- Condition.
- Detected vehicle data.
- Ovoko enrichment result.
- Allegro price result.
- Suggested category.
- Suggested shipping group.
- Readiness status.
- Blocking reasons.
- Warnings.
- Duplicate candidate product IDs.
- Created product ID once promoted.
- Error details.

### 4.3 Stage transitions

Recommended stage transition logic:

- `captured` to `needs_oem_review` if OEM is missing, unreadable, or low confidence.
- `captured` to `duplicate_candidate` if normalized OEM matches existing product/listing strongly.
- `captured` to `enrichment_pending` when OEM is present and no hard duplicate blocks exist.
- `enrichment_pending` to `enriched` after Ovoko enrichment completes.
- `enriched` to `price_suggested` after Allegro pricing completes.
- `price_suggested` to `category_mapped` after category/shipping suggestion exists.
- `category_mapped` to `ready_to_product` when staging readiness passes.
- `ready_to_product` to `product_created` after staff creates product.
- `product_created` to `ready_to_publish` when channel readiness passes.
- `ready_to_publish` to `published` after at least one channel publish succeeds.
- Any active status to `error` for blocking integration or data failures.
- Any non-final status to `archived` by explicit user action.

## 5. Product data model

### 5.1 Schema proposal overview

This is a logical schema proposal. Exact column types and indexes should be finalized during implementation.

### 5.2 Core catalog tables

#### `products`

Purpose: central canonical product record.

Suggested fields:

- `id`
- `sku`
- `status`: draft, active, reserved, sold, archived
- `type`: used_part, refurbished_part, new_part
- `condition`
- `title`
- `description`
- `internal_notes`
- `base_price_amount`
- `base_price_currency`
- `price_source`
- `category_id`
- `primary_identifier_id`
- `source_type`
- `source_reference`
- `created_from_staging_item_id`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Indexes:

- Unique `sku`.
- `status`.
- `category_id`.
- `created_from_staging_item_id`.

#### `product_identifiers`

Purpose: OEM and other product numbers.

Suggested fields:

- `id`
- `product_id`
- `type`: oem, manufacturer_part_number, alternative, internal, barcode
- `value_raw`
- `value_normalized`
- `source`
- `is_primary`
- `confidence`
- `created_at`
- `updated_at`

Indexes:

- `product_id`.
- `value_normalized`.
- Composite `type`, `value_normalized`.

#### `product_images`

Purpose: product image metadata.

Suggested fields:

- `id`
- `product_id`
- `staging_item_id`
- `disk`
- `path_original`
- `path_large`
- `path_thumbnail`
- `sort_order`
- `is_primary`
- `status`: uploaded, processing, ready, failed
- `width`
- `height`
- `mime_type`
- `checksum`
- `created_by`
- `created_at`
- `updated_at`

#### `product_categories`

Purpose: internal category tree.

Suggested fields:

- `id`
- `parent_id`
- `name`
- `slug`
- `default_shipping_group_id`
- `is_active`
- `sort_order`
- `created_at`
- `updated_at`

#### `attributes`

Purpose: internal attribute definitions.

Suggested fields:

- `id`
- `code`
- `name`
- `data_type`: string, number, boolean, enum, text, date
- `unit`
- `allowed_values`
- `is_filterable`
- `is_required_globally`
- `created_at`
- `updated_at`

#### `product_attribute_values`

Purpose: assigned product attributes.

Suggested fields:

- `id`
- `product_id`
- `attribute_id`
- `value_string`
- `value_number`
- `value_boolean`
- `value_json`
- `source`
- `created_at`
- `updated_at`

#### `vehicles`

Purpose: vehicle database.

Suggested fields:

- `id`
- `make`
- `model`
- `generation`
- `year_from`
- `year_to`
- `engine_code`
- `engine_size`
- `fuel_type`
- `body_type`
- `gearbox`
- `metadata`
- `created_at`
- `updated_at`

Indexes:

- Make/model/year.
- Engine code.

#### `vehicle_fitments`

Purpose: product-to-vehicle compatibility.

Suggested fields:

- `id`
- `product_id`
- `vehicle_id`
- `fitment_notes`
- `source`
- `confidence`
- `created_at`
- `updated_at`

### 5.3 Stock tables

#### `stock_items`

Purpose: current stock state.

Suggested fields:

- `id`
- `product_id`
- `warehouse_code`
- `location_bin`
- `quantity_on_hand`
- `quantity_reserved`
- `quantity_available`
- `status`: available, reserved, sold, missing, damaged, archived
- `created_at`
- `updated_at`

For one-off used parts, `quantity_on_hand` will usually be `1`.

#### `stock_movements`

Purpose: stock audit history.

Suggested fields:

- `id`
- `stock_item_id`
- `product_id`
- `movement_type`: intake, adjustment, reservation, sale, cancellation, return, sync_correction
- `quantity_delta`
- `reason`
- `reference_type`
- `reference_id`
- `created_by`
- `created_at`

### 5.4 Pricing tables

#### `price_suggestions`

Purpose: research results and suggested prices.

Suggested fields:

- `id`
- `product_id`
- `staging_item_id`
- `source`: allegro, manual, rule, import
- `currency`
- `min_price`
- `max_price`
- `median_price`
- `suggested_price`
- `sample_count`
- `confidence`
- `filters_applied`
- `raw_result_snapshot`
- `accepted_at`
- `accepted_by`
- `created_at`
- `updated_at`

#### `channel_price_rules`

Purpose: per-channel price rules.

Suggested fields:

- `id`
- `channel_id`
- `name`
- `currency`
- `markup_type`: fixed, percent, formula
- `markup_value`
- `min_margin_amount`
- `rounding_rule_id`
- `is_active`
- `created_at`
- `updated_at`

#### `exchange_rates`

Purpose: currency conversion snapshots.

Suggested fields:

- `id`
- `base_currency`
- `target_currency`
- `rate`
- `source`: nbp, ecb, manual
- `effective_date`
- `fetched_at`

### 5.5 Channel and marketplace tables

#### `channels`

Purpose: configured external/internal sales channels.

Suggested fields:

- `id`
- `code`: woo_current, storefront, ebay_de, ebay_fr, allegro, ovoko
- `name`
- `type`: shop, marketplace, enrichment, partner
- `currency`
- `locale`
- `is_enabled`
- `settings`
- `created_at`
- `updated_at`

#### `channel_listings`

Purpose: product listing state per channel.

Suggested fields:

- `id`
- `product_id`
- `channel_id`
- `status`: draft, ready, queued, publishing, published, sync_error, ended, archived
- `external_listing_id`
- `external_offer_id`
- `external_sku`
- `url`
- `channel_category_id`
- `policy_ids`
- `title`
- `description`
- `price_amount`
- `price_currency`
- `quantity`
- `readiness_status`
- `readiness_checked_at`
- `published_at`
- `last_synced_at`
- `last_error_at`
- `last_error_message`
- `metadata`
- `created_at`
- `updated_at`

Indexes:

- Unique `channel_id`, `external_listing_id` where present.
- Unique `channel_id`, `external_sku` where present.
- `product_id`, `channel_id`.
- `status`.

#### `channel_errors`

Purpose: structured integration failures.

Suggested fields:

- `id`
- `channel_id`
- `channel_listing_id`
- `product_id`
- `staging_item_id`
- `operation`: publish, revise, end, sync_stock, import_order, enrich, price_research
- `severity`: info, warning, error, critical
- `error_code`
- `message`
- `raw_request_snapshot`
- `raw_response_snapshot`
- `is_resolved`
- `resolved_by`
- `resolved_at`
- `created_at`

#### `publish_jobs`

Purpose: durable publish operation records.

Suggested fields:

- `id`
- `channel_id`
- `channel_listing_id`
- `product_id`
- `status`: pending, running, succeeded, failed, cancelled
- `operation`: create, update, revise, end, relist
- `idempotency_key`
- `queued_by`
- `approved_by`
- `started_at`
- `finished_at`
- `attempt_count`
- `last_error_message`
- `request_payload`
- `response_payload`
- `created_at`
- `updated_at`

### 5.6 Intake/staging tables

#### `staging_items`

Purpose: pre-product intake record.

Suggested fields:

- `id`
- `status`
- `source_type`
- `source_reference`
- `raw_identifier`
- `normalized_identifier`
- `condition`
- `warehouse_code`
- `location_bin`
- `notes`
- `detected_vehicle_data`
- `ovoko_enrichment_result`
- `allegro_price_result`
- `suggested_category_id`
- `suggested_shipping_group_id`
- `readiness_status`
- `blocking_reasons`
- `warnings`
- `duplicate_candidates`
- `created_product_id`
- `created_by`
- `created_at`
- `updated_at`

#### `staging_item_events`

Purpose: stage transition and processing history.

Suggested fields:

- `id`
- `staging_item_id`
- `event_type`
- `from_status`
- `to_status`
- `message`
- `metadata`
- `created_by`
- `created_at`

### 5.7 Order tables

#### `orders`

Purpose: central order records from channels and future storefront.

Suggested fields:

- `id`
- `channel_id`
- `external_order_id`
- `status`
- `ordered_at`
- `customer_name`
- `customer_email_hash`
- `shipping_address_snapshot`
- `billing_address_snapshot`
- `currency`
- `subtotal_amount`
- `shipping_amount`
- `tax_amount`
- `total_amount`
- `payment_status`
- `fulfillment_status`
- `raw_order_snapshot`
- `created_at`
- `updated_at`

#### `order_items`

Purpose: product lines in an order.

Suggested fields:

- `id`
- `order_id`
- `product_id`
- `channel_listing_id`
- `external_line_id`
- `sku`
- `title`
- `quantity`
- `unit_price_amount`
- `currency`
- `created_at`
- `updated_at`

### 5.8 Identity and audit tables

Use Laravel users plus role/permission tables, preferably through Spatie Laravel Permission or equivalent.

#### `audit_logs`

Purpose: durable action history.

Suggested fields:

- `id`
- `actor_user_id`
- `actor_type`: user, system, integration
- `action`
- `subject_type`
- `subject_id`
- `before_values`
- `after_values`
- `ip_address`
- `user_agent`
- `created_at`

## 6. Marketplace/channel model

### 6.1 Channels

Initial channels:

- `woo_current`: current WooCommerce shop during migration.
- `storefront`: future Laravel storefront.
- `ebay_de`: eBay Germany.
- `ebay_fr`: eBay France.
- `allegro`: Allegro.
- `ovoko`: Ovoko.

Important distinction:

- Woo/current shop is a migration channel.
- Future storefront is an internal channel/view over the central catalog.
- eBay DE/FR are publishing and order channels.
- Allegro is first a pricing research integration, later possibly a publishing channel.
- Ovoko is first an enrichment integration, later possibly publishing and stock sync.

### 6.2 Listing state model

Each channel listing stores:

- Channel.
- Status.
- External listing ID.
- External offer ID.
- External SKU.
- URL.
- Category ID.
- Policy IDs.
- Price.
- Quantity.
- Published timestamp.
- Last synchronized timestamp.
- Error status.
- Last error message.
- Readiness status.

Recommended statuses:

- `draft`: listing exists internally but is not ready.
- `ready`: passes readiness and can be queued.
- `queued`: publish/update requested.
- `publishing`: job is actively communicating with channel.
- `published`: channel confirms live listing.
- `sync_error`: last sync or publish failed.
- `ended`: listing was ended externally or internally.
- `archived`: historical listing, no active operations.

### 6.3 Channel adapter pattern

Each channel should implement an adapter contract such as:

- `checkReadiness(Product $product, Channel $channel)`
- `buildListingPayload(Product $product, ChannelListing $listing)`
- `publish(ChannelListing $listing)`
- `revise(ChannelListing $listing)`
- `syncStock(ChannelListing $listing)`
- `importOrders(Channel $channel)`
- `handleWebhook(array $payload)` where supported

The domain model should not know API details. API details belong in channel adapters and integration clients.

## 7. eBay DE/FR requirements

### 7.1 Required capabilities

The eBay adapter must eventually replace current WordPress eBay plugin logic and support:

- eBay DE publishing.
- eBay FR publishing.
- Marketplace-specific category mapping.
- Marketplace-specific required aspects.
- DE content/template.
- FR translated content/template.
- FR EUR conversion and markup rules using an approved exchange-rate source such as NBP.
- Business policies.
- Shipping groups: `shipping_30`, `shipping_50`, `shipping_130`.
- Publish logs.
- Stock sync.
- Order sync.
- Duplicate guards.

### 7.2 eBay listing generation

Separate internal data from marketplace output:

- Internal title and description are canonical.
- eBay DE title/description can be generated from DE template rules.
- eBay FR title/description can be generated from FR translation/template rules.
- Category-specific aspects must be validated before publish.
- Business policy IDs must be selected from channel settings or category/shipping rules.

### 7.3 eBay duplicate guards

Duplicate protection should include:

- Internal check for existing active channel listing for product/channel.
- External SKU uniqueness check.
- Normalized OEM match warning for active products.
- Optional eBay search/API verification before risky publish.
- Idempotency key per publish job.

### 7.4 eBay stock/order sync

Rules:

- When any channel order sells a one-off part, central stock becomes unavailable.
- Stock updates should be queued immediately to all published channels.
- If eBay order imports first, it should reserve/reduce stock and trigger updates to Woo/storefront/other marketplaces.
- Failed stock sync is critical and must appear in Error Center.

## 8. Ovoko requirements

### 8.1 Role of Ovoko

Ovoko should become a channel/adapter and enrichment source, not the master product system.

Initial approach:

- Read-only enrichment by OEM/part number.
- Store raw enrichment result snapshot.
- Map useful fields to suggested product data.
- Require staff approval before creating or updating central products.

Later approach:

- Product publishing to Ovoko after readiness and approval.
- Stock synchronization after product/listing matching is reliable.
- Order import if supported and operationally needed.

### 8.2 Integration rules

- Use official/API-based integration where possible.
- Avoid blind browser scraping.
- Store rate limits and integration errors.
- Never allow Ovoko data to overwrite manually approved product data without review.
- Keep enrichment confidence and source metadata.

## 9. Allegro requirements

### 9.1 Initial role

Allegro should first be used for price research by OEM through API-based search.

Capabilities:

- Search by normalized OEM and variants.
- Collect relevant active/completed offer prices where API permits.
- Filter out unrelated offers.
- Filter out outliers.
- Calculate min, max, median, sample count, and confidence.
- Store raw result summary and applied filters.
- Create price suggestions, not automatic final prices.

### 9.2 Filtering and confidence

Filtering rules should consider:

- OEM exact/partial match.
- Category match where known.
- Product condition.
- Offer title language and relevance.
- Exclude obviously unrelated bundles or damaged-only offers if product is not comparable.
- Exclude extreme outliers using configurable thresholds.

Confidence can be based on:

- Number of relevant offers.
- OEM match quality.
- Category consistency.
- Price dispersion.
- Recency of data.

### 9.3 Later Allegro publishing

Later publishing should be treated as a separate channel capability with its own readiness rules, category/parameter mapping, stock sync, order import, and approval flow.

## 10. Pricing strategy

### 10.1 Base price

Each product should have:

- Base price amount.
- Base price currency.
- Price source.
- Price confidence.
- Manual override flag.
- Last pricing review date.

Price sources:

- Manual.
- Allegro median suggestion.
- Imported Woo price.
- Rule-based price.
- Partner/vendor suggestion.

### 10.2 Allegro median suggestion

Store:

- Min price.
- Max price.
- Median price.
- Suggested price.
- Currency.
- Sample count.
- Confidence.
- Filters applied.
- Raw result snapshot.

Do not apply Allegro suggestions automatically unless explicit rules are approved later.

### 10.3 Manual override

Manual override should:

- Lock base price from automatic updates unless user permits recalculation.
- Require audit log entry.
- Optionally require reason/comment for large changes.

### 10.4 Channel price rules

Channel pricing can be derived from base price:

- Add fixed markup.
- Add percentage markup.
- Apply marketplace fee compensation.
- Apply currency conversion.
- Apply rounding.
- Enforce minimum price or margin.

Example:

- Base price in PLN.
- eBay DE price in EUR = PLN base / exchange rate + DE markup, rounded to `.99`.
- eBay FR price in EUR = converted base + FR markup + shipping/market adjustment, rounded to `.99`.

### 10.5 Currency conversion

For eBay FR and other EUR channels:

- Store exchange-rate source, such as NBP.
- Store rate snapshot used for each price calculation.
- Recalculate drafts automatically if rules allow.
- Avoid silently changing published prices without approval or configured automation.

### 10.6 Rounding rules

Suggested rounding rules:

- Round up to nearest `0.99` for marketplace display.
- Minimum threshold rounding for low-value parts.
- Optional category-specific rounding.
- Store calculated price and rule version.

## 11. Category and shipping mapping

### 11.1 Internal category model

Create an internal category tree that is independent of WooCommerce and marketplaces.

Each internal category can define:

- Name.
- Parent category.
- Default shipping group.
- Required internal attributes.
- Suggested image requirements.
- Default title pattern.
- Default description template.

### 11.2 Channel category mapping

Use a mapping table:

- Internal category.
- Channel.
- External category ID.
- External category name.
- Required aspects/parameters.
- Mapping confidence.
- Last reviewed date.
- Active flag.

### 11.3 Shipping groups

Preserve the concepts:

- `shipping_30`
- `shipping_50`
- `shipping_130`

But implement them as internal shipping groups, not hardcoded plugin assumptions.

Shipping group fields:

- Code.
- Name.
- Dimensions/weight assumptions.
- Cost/rate.
- Carrier/service.
- Channel-specific policy IDs.
- Active flag.

### 11.4 Required aspects and attributes

For eBay and future channels:

- Store required marketplace aspects per channel category.
- Map internal attributes to channel aspects.
- Readiness should block publishing if required aspects are missing.
- Admin should show suggested fixes and direct edit links.

## 12. Readiness system

### 12.1 Readiness levels

Readiness should operate at two levels:

1. **Product readiness:** Is the product good enough to exist as an active central product?
2. **Channel readiness:** Is the product ready to publish or update on a specific channel?

### 12.2 Readiness result

A readiness result should produce:

- Ready: yes/no.
- Blocking reasons.
- Warnings.
- Suggested fixes.
- Last checked timestamp.
- Rule version.

### 12.3 Example product readiness blockers

- Missing OEM/identifier.
- Missing images.
- Missing price.
- Missing internal category.
- Missing stock item or unavailable stock.
- Duplicate candidate not resolved.
- Missing condition.

### 12.4 Example channel readiness blockers

- Missing channel category mapping.
- Missing required eBay aspects.
- Missing business policy.
- Missing shipping group.
- Missing channel title/description.
- Missing FR translation/content for eBay FR.
- Missing price in channel currency.
- Duplicate active listing.
- Stock unavailable.
- Channel credentials disabled.

### 12.5 Warnings vs blockers

Warnings should not block publishing but should be visible:

- Low image count but acceptable.
- Low price confidence.
- Vehicle data incomplete.
- Category mapping has low confidence.
- Exchange rate older than preferred threshold.

## 13. Admin UI

### 13.1 Dashboard

Overview widgets:

- Staging items needing review.
- Products ready to publish.
- Publish failures.
- Stock sync failures.
- Orders awaiting handling.
- Queue health.
- Recent channel errors.

### 13.2 Product Command Center

Main product grid:

- Search by SKU, OEM, title, external ID, channel SKU.
- Filters by status, category, channel state, stock status, readiness, missing data.
- Bulk actions for readiness recalculation, category assignment, export, and queue publish where permitted.

### 13.3 Mobile Intake Queue

Focused screen for captured staging items:

- Photo preview.
- OEM review.
- Duplicate warnings.
- Enrichment status.
- Pricing status.
- Create product action.

### 13.4 Staging Items

Detailed staging management:

- Status pipeline.
- Source data.
- Photos.
- Notes/location/condition.
- Ovoko enrichment result.
- Allegro price result.
- Suggested category/shipping.
- Readiness blockers.
- Promotion to product.

### 13.5 Product Details

Tabbed product view:

- Summary.
- Identifiers/OEM.
- Images.
- Vehicle/Fitment.
- Attributes.
- Pricing.
- Stock.
- Channel Listings.
- Readiness.
- Audit log.

### 13.6 Images

Features:

- Drag-and-drop reorder.
- Primary image selection.
- Upload status.
- Image processing status.
- Marketplace preview.

### 13.7 Vehicle/Fitment

Features:

- Add/edit vehicle compatibility.
- Import suggestions from enrichment.
- Confidence/source display.
- Bulk fitment tools later.

### 13.8 Pricing

Features:

- Base price editing.
- Allegro price suggestion review.
- Accept/ignore suggestion.
- Channel price preview.
- Currency/rate snapshot.
- Price history.

### 13.9 Channel Listings

Features:

- Per-channel status.
- External IDs and URLs.
- Category/policy/price/quantity.
- Last synced time.
- Last error.
- Publish/update/end actions with permissions.

### 13.10 Readiness

Features:

- Product readiness summary.
- Channel readiness cards.
- Blocking reasons.
- Warnings.
- Suggested fixes.
- Recalculate action.

### 13.11 Publish Center

Features:

- Products ready for selected channels.
- Bulk queue with approval.
- Publish job status.
- Logs and failures.
- Retry/cancel controls.

### 13.12 Orders

Features:

- Imported orders by channel.
- Product matching.
- Stock reservation/reduction.
- Fulfillment status.
- Channel order links.

### 13.13 Stock

Features:

- Stock by warehouse/bin.
- Available/reserved/sold/missing.
- Adjustments with reason.
- Movement history.
- Stock sync status.

### 13.14 Error Center

Features:

- Channel errors.
- Failed jobs.
- Failed publish attempts.
- Failed stock sync.
- Filter by severity/channel/operation.
- Resolve/ignore/retry actions.

### 13.15 Import/Export

Features:

- Woo import/sync.
- CSV import/export.
- Mapping review.
- Staging import history.

### 13.16 Settings

Features:

- Channels and credentials.
- Shipping groups.
- Category mappings.
- Price rules.
- Exchange-rate settings.
- Business policies.
- Readiness rules.

### 13.17 Users/Roles

Features:

- User management.
- Role assignment.
- Permission management.
- Approval permissions.

### 13.18 Future Partner Portal

Features:

- Restricted partner login.
- Partner product submissions.
- Partner inventory/status view.
- Admin approval queue.
- No direct marketplace publish permission.

## 14. Migration plan from WordPress/Woo

### Phase 1: Product Hub as side system

WooCommerce remains the live store.

Goals:

- Build Laravel foundation.
- Import Woo products into Product Hub.
- Map Woo categories to internal categories.
- Import Woo images, prices, stock, SKUs, and identifiers where available.
- Store Woo product ID as external reference.
- Product Hub does not yet control live operations.

Success criteria:

- Product Hub can display imported catalog.
- Imported data quality issues are visible.
- No impact on live Woo operations.

### Phase 2: Mobile intake/staging creates Woo products

Goals:

- Staff uses Product Hub mobile intake.
- New items enter staging.
- Product Hub creates/updates Woo products via Woo API after approval.
- Gmail legacy intake can remain temporarily as a fallback/import source.

Success criteria:

- New products can be captured without Gmail.
- Woo receives Product Hub-created products.
- Product Hub stores mapping to Woo product ID.

### Phase 3: Product Hub controls marketplace publishing logic

Woo still serves the frontend.

Goals:

- Move eBay DE/FR readiness and publishing decisions into Product Hub.
- Keep Woo as storefront and possibly as channel source for frontend display.
- Begin reducing WordPress plugin responsibilities.
- Product Hub stores listing state and publish logs.

Success criteria:

- eBay DE/FR publish flow can be tested from Product Hub.
- Duplicate guards and readiness are stronger than current plugin chain.
- Rollback path exists by keeping old plugin disabled/enabled only as planned.

### Phase 4: Build Laravel storefront/checkout

Goals:

- Build storefront MVP using Product Hub catalog and stock.
- Run parallel testing against Woo storefront.
- Validate SEO, checkout, payment, shipping, order handling, and customer emails.

Success criteria:

- Storefront can list products accurately.
- Checkout can create orders and reduce stock in staging/test mode.
- Staff can compare Woo vs Laravel behavior.

### Phase 5: Switch storefront from Woo to Laravel

Goals:

- Make Laravel storefront the live shop.
- Keep Woo read-only/archive if needed.
- Product Hub becomes operational source for shop + marketplaces.

Success criteria:

- Woo no longer required for live product/order operations.
- Orders and stock are controlled centrally.
- Monitoring confirms stable operations.

### Phase 6: Remove old WordPress plugins once stable

Goals:

- Disable old eBay and synchronization plugins.
- Keep historical data/export as needed.
- Document final rollback/archive approach.

Success criteria:

- No live business process depends on old plugin chain.
- WordPress can be retired, archived, or used only for content if desired.

## 15. Infrastructure recommendation

### 15.1 MVP infrastructure

For MVP/first months:

- One production-like VPS for staging/internal testing.
- Managed or VPS PostgreSQL.
- Redis on same VPS if low load, separated later.
- Meilisearch on same VPS or separate small instance.
- S3-compatible object storage.
- Basic backups and uptime monitoring.
- GitHub/GitLab CI for tests and deployment.

This is enough while Woo remains live and Product Hub is not yet the critical production storefront.

### 15.2 Production infrastructure

For production:

- Cloud/VPS/datacenter hosting, not local physical server.
- Separate web and queue processes.
- PostgreSQL with automated backups.
- Redis with persistence where appropriate.
- S3-compatible object storage for images.
- CDN for images if traffic requires it.
- Staging environment mirroring production services.
- Dedicated monitoring and error tracking.

A practical small-team production setup:

- 1 app VPS with web + scheduler.
- 1 worker VPS or worker process pool.
- Managed PostgreSQL if budget allows.
- Managed Redis if budget allows, otherwise monitored VPS Redis.
- Object storage from a provider.
- Automated deploys.

### 15.3 Staging environment

Staging should:

- Use separate database and object storage bucket.
- Use sandbox/test credentials for marketplaces where available.
- Never publish to live channels unless explicitly marked and approved.
- Support import of sanitized production snapshots for testing.

### 15.4 Backups

Backup plan:

- Daily full database backup.
- More frequent WAL/PITR if managed PostgreSQL supports it.
- Object storage versioning or scheduled backup copy.
- Weekly offsite backup copy.
- Monthly restore test.
- Local NAS can be used as an additional backup destination, not as production hosting.

### 15.5 Monitoring and logs

Monitoring stack:

- Uptime monitoring.
- Application error tracking.
- Laravel log aggregation.
- Horizon queue monitoring.
- Database storage/CPU/memory monitoring.
- Object storage usage monitoring.
- Integration health dashboard.

Alerts:

- Failed publish jobs.
- Failed stock sync jobs.
- Queue backlog above threshold.
- eBay/Ovoko/Allegro authentication failure.
- Database backup failure.
- Disk or memory pressure.

## 16. Security

### 16.1 Roles

Recommended roles:

- `super_admin`: full access.
- `admin`: operational admin, except sensitive system secrets if desired.
- `intake_staff`: mobile intake and staging creation.
- `catalog_manager`: product/category/attribute management.
- `pricing_manager`: price suggestions and price approvals.
- `publisher`: queue publish jobs where readiness passes.
- `publishing_approver`: approve risky or bulk publish actions.
- `order_manager`: order and fulfillment access.
- `stock_manager`: stock adjustments and locations.
- `partner_vendor`: future restricted partner role.

### 16.2 Credential storage

Rules:

- Store API tokens encrypted at rest.
- Use Laravel encryption for app-managed secrets.
- Prefer environment variables or secret manager for master credentials.
- Store OAuth refresh tokens securely.
- Restrict credential visibility in admin UI.
- Audit credential changes.

### 16.3 Publishing permissions

Publishing should require:

- Channel readiness pass.
- User permission.
- Approval for bulk actions or high-risk channels.
- Audit log entry.
- Optional two-step approval for new channel activation.

Partners/vendors must not be able to publish directly to company marketplaces. They can submit items or updates for review.

### 16.4 Audit and compliance

Audit:

- Product changes.
- Price changes.
- Stock movements.
- Publish approvals.
- Channel credential changes.
- Role/permission changes.
- Manual external ID changes.

Privacy:

- Store order customer data only as needed.
- Consider hashing email for search/matching where full email is not required.
- Limit access to customer data by role.

## 17. Development plan for 6 months

### Month 1: Architecture, data model, admin skeleton, staging model

Deliverables:

- Final technical architecture.
- Database schema draft and migrations for core tables.
- Laravel project skeleton.
- Filament admin skeleton.
- Users/roles/permissions.
- Staging item model and admin screens.
- Product/catalog base models.
- Initial audit logging.

Milestone:

- Admin can create/view staging items and draft products.

### Month 2: Mobile intake PWA, product photos, OEM capture, duplicate detection

Deliverables:

- Mobile PWA login and intake form.
- Camera/photo upload.
- Multiple photos per staging item.
- OEM normalization.
- Warehouse/bin and condition fields.
- Duplicate candidate detection by normalized OEM.
- Upload progress and retry.

Milestone:

- Staff can capture real parts into staging from a phone.

### Month 3: Ovoko enrichment, Allegro price research, category/shipping mapping

Deliverables:

- Ovoko enrichment adapter, read-only.
- Allegro price research adapter.
- Price suggestion storage.
- Internal category model.
- Channel category mapping foundation.
- Shipping groups `shipping_30`, `shipping_50`, `shipping_130` as internal records.
- Initial category/shipping suggestion rules.

Milestone:

- Staging items can be enriched, priced, and category/shipping suggested.

### Month 4: Central product catalog, readiness engine, Woo sync bridge

Deliverables:

- Product promotion from staging.
- Product readiness checks.
- Channel readiness framework.
- Woo import/sync bridge.
- Product Hub creates/updates Woo products via API after approval.
- Stock model and basic stock movements.

Milestone:

- Product Hub can create central products and push approved products to Woo.

### Month 5: Marketplace adapters eBay DE/FR, publish/readiness/logging

Deliverables:

- eBay DE adapter foundation.
- eBay FR adapter foundation.
- eBay category/aspect readiness.
- Business policy/shipping group mapping.
- DE/FR content template handling.
- FR exchange-rate/markup pricing logic.
- Publish jobs and logs.
- Duplicate guards.
- Stock sync prototype.

Milestone:

- Controlled test publishing/update flow exists for eBay DE/FR.

### Month 6: Storefront planning or first storefront MVP, migration testing, stabilization

Deliverables:

- Storefront architecture decision.
- First storefront MVP or detailed storefront implementation plan.
- End-to-end migration test from intake to Woo/eBay.
- Monitoring, backup, restore test.
- Error Center improvements.
- Operational documentation.
- Stabilization and bug fixing.

Milestone:

- Product Hub is ready to become the operational center for intake, catalog, readiness, and selected channel publishing.

## 18. What to reuse from the current system

### 18.1 Reuse conceptually

Reuse the business knowledge, not the WordPress-specific architecture.

Good concepts to reuse:

- eBay DE/FR category mapping.
- Shipping groups `shipping_30`, `shipping_50`, `shipping_130`.
- Existing readiness rules and known blockers.
- FR translation/template logic.
- NBP EUR conversion logic.
- Stock sync lessons and edge cases.
- Report formats/logging that staff already understand.
- Auto-runner patterns where they represent real operational needs.
- Existing SKU conventions if they work reliably.
- Known marketplace error handling cases.

### 18.2 Do not reuse blindly

Avoid copying:

- WordPress plugin lifecycle assumptions.
- WooCommerce as master product model.
- Hardcoded category IDs as internal truth.
- Hidden cron behavior with weak observability.
- Browser scraping workflows.
- Logic coupled to WordPress admin screens.
- Marketplace state stored only as post meta.

### 18.3 Migration extraction plan

During Phase 1, export and document:

- Current Woo category IDs and names.
- Current eBay category mappings.
- Current shipping group mapping rules.
- Current business policy IDs.
- Current FR template examples.
- Current NBP conversion formula.
- Current readiness blocker list.
- Current stock sync failure cases.
- Current report examples.

Then convert them into Product Hub configuration tables and explicit readiness rules.

## 19. Workflows

### 19.1 New part intake workflow

1. Staff captures staging item in mobile PWA.
2. Product Hub stores photos and raw OEM.
3. System normalizes OEM.
4. Duplicate detection runs.
5. If duplicate candidate exists, staff reviews.
6. Ovoko enrichment runs if allowed.
7. Allegro price research runs if allowed.
8. Category and shipping suggestions are generated.
9. Readiness checks run.
10. Staff fixes blockers.
11. Staff promotes staging item to product.
12. Product readiness runs.
13. Channel readiness runs.
14. Publisher approves selected channels.
15. Publish jobs run.
16. Listings and errors are stored.

### 19.2 Woo migration workflow

1. Product Hub imports Woo product.
2. Stores Woo ID as external reference.
3. Extracts SKU, title, price, stock, images, categories, attributes.
4. Maps Woo category to internal category.
5. Creates missing data warnings.
6. Does not overwrite Woo until Phase 2 approval.

### 19.3 Stock sold workflow

1. Order is imported from a channel.
2. Product Hub matches order item to product/listing.
3. Stock is reserved or reduced.
4. Stock movement is recorded.
5. Stock sync jobs are queued for all other live channels.
6. Failed sync appears in Error Center.
7. Staff resolves any sync failures.

### 19.4 Publish workflow

1. Staff selects product/channel.
2. Product Hub calculates readiness.
3. Blocking reasons must be resolved.
4. Staff with permission queues publish.
5. Publish job creates/revises listing through adapter.
6. Channel response is stored.
7. Listing status updates.
8. Errors are visible and retryable.

## 20. Queue/job list

Recommended queued jobs:

- `ProcessUploadedImageJob`
- `GenerateImageVariantsJob`
- `RunOcrOnStagingImageJob`
- `NormalizeIdentifierJob`
- `DetectDuplicateStagingItemJob`
- `EnrichFromOvokoJob`
- `ResearchAllegroPriceJob`
- `SuggestCategoryAndShippingJob`
- `RecalculateProductReadinessJob`
- `RecalculateChannelReadinessJob`
- `ImportWooProductsJob`
- `SyncProductToWooJob`
- `PublishChannelListingJob`
- `ReviseChannelListingJob`
- `EndChannelListingJob`
- `SyncStockToChannelJob`
- `ImportChannelOrdersJob`
- `HandleChannelWebhookJob`
- `FetchExchangeRatesJob`
- `GenerateOperationalReportJob`
- `CleanupTemporaryUploadsJob`

Queue priorities:

- Critical: stock sync after sale, order import, failed stock retry.
- High: publishing and revise operations.
- Medium: enrichment and price research.
- Low: image variants, reports, cleanup.

## 21. Integration list

### 21.1 WooCommerce

Purpose:

- Migration import.
- Temporary live storefront sync.
- Product creation/update during transition.

Direction:

- Phase 1: Woo to Product Hub import.
- Phase 2 onward: Product Hub to Woo create/update.

### 21.2 Future Laravel storefront

Purpose:

- Native shop frontend.
- Native checkout.
- Direct use of Product Hub catalog, pricing, stock, and orders.

Direction:

- Internal module, not external master.

### 21.3 eBay DE

Purpose:

- Publishing.
- Revise/update.
- Stock sync.
- Order import.

### 21.4 eBay FR

Purpose:

- Publishing with FR content.
- EUR conversion/markup.
- Revise/update.
- Stock sync.
- Order import.

### 21.5 Ovoko

Purpose:

- Read-only enrichment first.
- Later publishing and stock sync after approval.

### 21.6 Allegro

Purpose:

- Price research first.
- Later optional publishing, stock sync, and order import.

### 21.7 Exchange rates

Purpose:

- NBP or equivalent official exchange-rate source.
- EUR conversion for eBay FR and other EUR channels.

### 21.8 OCR/scanning

Purpose:

- Assist OEM capture.

Options:

- Browser-assisted manual capture first.
- Server-side OCR later.
- Cloud OCR only if privacy/cost is acceptable.

## 22. Technical risks

### 22.1 Marketplace API complexity

Risk: eBay, Allegro, Woo, and Ovoko APIs have different data models, authentication, rate limits, and failure modes.

Mitigation:

- Use channel adapters.
- Store raw request/response snapshots.
- Implement readiness before publish.
- Start read-only where possible.
- Build strong Error Center.

### 22.2 Data quality from existing Woo/plugin system

Risk: Existing data may have missing OEMs, inconsistent categories, incomplete stock, and plugin-specific assumptions.

Mitigation:

- Import into Product Hub without immediately overwriting.
- Add data quality reports.
- Use staging/review queues.
- Preserve original external references.

### 22.3 Overselling one-off parts

Risk: Quantity is usually one, and multiple channels can sell the same part.

Mitigation:

- Central stock ownership.
- Immediate stock reservation on order import.
- High-priority stock sync jobs.
- Monitoring and alerts for stock sync failures.
- Conservative publish rules during migration.

### 22.4 Image volume and upload reliability

Risk: Mobile uploads can fail, and product images are business-critical.

Mitigation:

- Upload progress and retry.
- Object storage.
- Background processing.
- Image status visibility.
- Preserve original image.

### 22.5 Readiness rule drift

Risk: Marketplace requirements change, especially eBay aspects and policies.

Mitigation:

- Store rule versions.
- Recalculate readiness regularly.
- Make missing aspects visible.
- Keep category/aspect mappings configurable.

### 22.6 Scope creep

Risk: Building intake, catalog, marketplaces, orders, and storefront can become too large.

Mitigation:

- Keep six-month goals phased.
- Treat storefront as Month 6 planning/MVP, not full replacement unless earlier phases are stable.
- Avoid partner portal implementation until core operations work.

### 22.7 Dependency on old Woo during transition

Risk: The system may remain half-migrated too long.

Mitigation:

- Define phase success criteria.
- Move one responsibility at a time.
- Track which system owns each business capability.
- Retire old plugins only after Product Hub has proven replacement workflows.

## 23. Open questions

Business/process questions:

- What is the exact SKU format Product Hub should generate for new products?
- Which staff roles should be allowed to approve publishing?
- Should every product require vehicle fitment, or only certain categories?
- What minimum photo count should be required by category?
- Which categories require shipping groups 30/50/130 by default?
- Should price suggestions expire after a certain number of days?
- What is the acceptable delay for stock synchronization after an order?

Integration questions:

- Which Ovoko API capabilities are available for enrichment, publishing, and stock sync?
- Which Allegro API endpoints and permissions are available for price research?
- Which eBay API mode will be used for inventory/offers/policies?
- Are current eBay business policy IDs stable and documented?
- How should Woo products created by Product Hub be identified in Woo metadata?

Infrastructure questions:

- Preferred hosting provider and region?
- Managed PostgreSQL budget or self-managed VPS database?
- Preferred object storage provider?
- Error tracking provider preference?
- Backup retention requirements?

Storefront questions:

- Should Laravel storefront use a traditional Blade/Livewire approach, Inertia, or a separate frontend?
- Which payment provider should be used?
- Which shipping carriers and rates are required?
- What SEO URL structure must be preserved from Woo?

## 24. Recommended immediate next steps

1. Approve the modular monolith direction.
2. Choose PostgreSQL unless there is a strong operational reason for MySQL.
3. Inventory current Woo/eBay plugin logic and export mappings.
4. Define internal category and shipping group model.
5. Define SKU/OEM normalization rules.
6. Create the Month 1 implementation backlog.
7. Build only the foundation and staging/admin skeleton first.
8. Avoid replacing live marketplace publishing until readiness, duplicate guards, and logging are proven.
