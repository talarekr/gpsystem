# Fresh product export to WystawCzesc

Independent OwnerAdmin tool: **Części → Eksport do WystawCzesc**. The old Migration
Runner, its runs, checkpoints, photos, A/B/C and supplements are never consulted.
No production export or deployment is part of this change.

## HTTP contract

`GET /admin/tools/product-migration-export` renders the page.
`POST` on that URL accepts `action=prepare`, `type=czesci|sprzedane|do_wystawienia`,
`mode=sample|full` and returns a new `export_id` before starting the potentially long capture.

`GET /admin/tools/product-migration-export/{export}` returns owner-scoped progress.
`POST` on that URL accepts `action=capture|tick|stop|resume`.
`GET` on that URL with `?download={file}` downloads a completed, hash-verified file.
All mutations of export artifacts use POST with the normal CSRF guard. Existing
read-only session and SQL middleware prevent session/business database writes.
GET never starts or advances an export. Download names are allowlisted. Private
files/directories use 0600/0700 and responses disable caching. Only the creating
OwnerAdmin can inspect, advance or download a run.

## Capture and resource boundaries

Prepare allocates only a private directory, under
`storage/app/private/product-migration-exports/{export_id}`. Capture uses Query
Builder, no Eloquent models, observers, jobs, marketplace clients or resolvers.
MySQL uses one `REPEATABLE READ, READ ONLY` transaction and requires all captured
tables to be InnoDB. SQLite uses one read transaction (including the test harness's
outer transaction). The SELECT-only application guard covers the capture as well.

Tables: parts, cars, part_categories, storage_locations, part_images,
marketplace_listings; optional marketplace_accounts (safe identity projection),
marketplace_category_mappings, allegro_shipping_rates, part_translations and
category_translations. Credentials are never selected from accounts. Of
api_settings, only marketplace_id survives projection. Stored payloads pass the
existing migration sanitization policy. No data is fetched from providers.

`czesci`: all parts; `sprzedane`: status=sold; `do_wystawienia`: needs_listing=true.
No stock, storefront visibility or listing inference changes those rules.

Sample selects up to five rows from the first 100 eligible IDs in the requested set, ranking car/category/storage,
description, photos (including multiple photos), price, priced listings, explicit
DE listings and Ovoko listings. Ties use ascending part ID. There is no randomness.
Selected IDs appear in status and the manifest. With fewer than five eligible rows,
all available rows are exported. Full always creates its own ID and fresh capture.

Rows are captured in batches of 100 parts with related rows loaded only for those
IDs. Memory scales with one batch and its relations, not the whole catalog; an
exceptionally large individual product/gallery still costs memory. A private
newline-delimited capture is sealed only after the transaction succeeds. Metadata
includes database capture time, finish time, schema inventory, source Git commit,
schema version and SHA256. Revision metadata is read through PHP/configuration, without executing Git; ZIP deployments without it are marked unavailable.

CSV projection runs in ticks of at most 25 parts. It uses only the new capture,
not the database. Byte offsets are committed atomically in a private checkpoint;
uncommitted tails are truncated on the next tick. Stop pauses projection between
ticks. Resume verifies the capture hash. Reload retains export ID in per-user
localStorage and reads authoritative server state; a capturing run is polled.

A live database transaction cannot be resumed across HTTP requests. Capture runs
in one request, ignores browser disconnects, and disables PHP's execution timer.
An external worker/proxy kill or capture failure requires a **new export**; partial
captures are never adopted. Stop/Resume apply to CSV projection, not to a running
DB transaction. Deployments must allow the capture request to complete; a very
large catalog may need a dedicated worker in a later change. This implementation
does not claim a multi-request atomic snapshot or silently reuse old membership.
No deployment settings are changed here.

## Relationship semantics

Primary car relationship is parts.car_id → cars.id; source/external IDs and Ovoko
car identity remain additional identifiers. Car generation uses model_variant,
year production_year, engine engine_capacity_cm3 (cm³), gearbox gearbox_type,
fuel fuel_type. Missing columns/values remain NULL. vehicle_snapshot is retained,
and its steering_side takes precedence over cars.steering_side. part_position
uses review_metadata.part_position, then legacy_payload.part_position/position.

Allegro category is emitted only for one distinct locally mapped Allegro category.
Shipping rate requires exactly one associated Allegro account from local listings
and exactly one matching rate name for that account. No hardcoded fallback or
resolver call is made. A rate name does not reconstruct carrier prices/settings.

Main CSV projects **only eBay DE and Ovoko** listing shortcuts. eBay classification
collects explicit ebay_de/ebay_fr channel/account codes and locally stored
marketplaceId/marketplace_id (including offer.marketplaceId), plus account
api_settings.marketplace_id. All signals must agree. URL domains are never used
for classification. Unresolved/conflicting eBay records suppress the DE shortcut
conservatively and generate an issue. A DE+FR pair correctly yields one DE row.
Exactly one matching listing gives a shortcut; zero/multiple yields NULL values.
`listing_count` is the number of confidently classified matching records.
`resolution_status` and `listing_resolution_status` are equivalent explicit aliases:
unique, missing, multiple, or market_unresolved (eBay).

Prices from parts and prices from listings are independent; no conversion or
fallback overwrites them. parts.ebay_price is the locally stored PLN base, not
an EUR DE offer price. Listing prices retain their own currency and timestamps.
No stored value is described as remotely verified/current at the provider.

## Store URLs

Actual public route: `storefront.product`, `GET /produkt/{slug}`. PartController
accepts a slug or local ID, and applies storefront visibility rules. store_url
uses configured app.url only when it identifies the configured Polish storefront;
otherwise NULL. Routing comes from the named route; slug falls back to local ID.
A URL is an identity candidate for redirect mapping, **not a promise of HTTP 200**
(sold/hidden parts can be unavailable). No storefront request is sent. Original
legacy_url, legacy_slug and slug are preserved separately.

## Files and import encoding

- Exactly one of czesci.csv, sprzedane.csv, do_wystawienia.csv, identical headers.
- zdjecia_czesci.csv: only selected parts; exact stored paths, original image IDs,
  sort_order/is_primary and deterministic import_order (primary first, then sort
  order, image ID and path). Code photos are included. No disk scan/copy/download.
- marketplace_listingi_czesci.csv: all locally associated listings, including FR
  and historical Allegro, only for selected parts. Full sanitized source metadata.
- relacje_czesci.csv: per-part car/category/location records, mappings and translations
  to avoid losing reference data that has no flat convenience column.
- issues.csv: missing/orphan relations, missing images/paths, ambiguous listings,
  eBay classification conflicts, missing shortcut URLs and ambiguous categories.
- manifest.json: identity, selection, times, commit, schema, counts, capture hash,
  file hashes and exact ordered column lists.

Photo source_path is the authoritative key together with part_id/image_id; basename
is only a label. copy_relative_path strips known public-storage prefixes only and
rejects absolute/remote/traversal paths. Physical existence is not asserted.
Preserve directory structure when copying files. Presentation variants remain in
image source_metadata; this manifest enumerates original gallery/code images,
not every generated thumbnail. Files without a DB relationship cannot be assigned
by guesswork. No old photo-pipeline files are touched.

CSV: UTF-8 BOM, semicolon delimiter, RFC-style double-quoted fields, JSON for
arrays/objects, `\N` for NULL, leading backslash doubled for literal values.
Values starting with whitespace/control characters then =,+,@,- receive a leading
apostrophe against formula injection. An importer must reverse these conventions
(and remove the apostrophe only when this exact guard applies). IDs/SKUs/codes
must be imported as text; opening CSV in Excel with automatic typing can destroy
leading zeros. HTML/newlines are not truncated. Source parts JSON retains additional
non-secret columns, and relationship JSON retains car/reference data.

## Verification

PHPUnit feature tests cover each type in sample/full mode, identical columns,
SELECT-only capture, zero HTTP calls, joins, local Ovoko car ID, photos/listings
restricted to the selection, DE+FR, duplicate DE, conflicting market, prices,
store URLs, fresh capture, stop/resume, output rollback, empty sets, authorization,
owner isolation, download hashes and CSV encoding/escaping. Chromium test uses
intercepted traffic only to exercise preparation, capture, failure, reload,
Stop/Resume and download links. MySQL production-scale timing is not measured by
SQLite tests; production is not started or queried.

## Exact main columns (ordered)

```text
part_id
source_system
external_id
sku
part_number
oem_number
manufacturer_code
additional_part_codes
status
quantity
needs_listing
needs_review
is_visible_storefront
sale_source
sold_at
created_at
updated_at
name
short_description
description
condition_notes
internal_note
category_id
category_name
category_path
allegro_category_id
allegro_category_source
part_position
steering_side
steering_side_source
weight_kg
length_cm
width_cm
height_cm
storage_location_id
storage_location_name
storage_location_description
car_id
car_source_system
car_external_id
ovoko_car_id
car_make
car_model
car_generation
car_year
car_vin
car_engine
car_engine_code
car_gearbox
car_gearbox_code
car_fuel
car_body_type
vehicle_snapshot
store_price
store_currency
allegro_price
ovoko_price
ebay_price
allegro_shipping_rate_name
allegro_shipping_rate_id
allegro_shipping_account_id
store_legacy_url
store_slug
store_legacy_slug
store_url
image_count
primary_image_path
images_json
legacy_payload
review_metadata
source_part_json
ebay_de_listing_count
ebay_de_resolution_status
ebay_de_listing_resolution_status
ebay_de_listing_local_id
ebay_de_external_listing_id
ebay_de_external_offer_id
ebay_de_marketplace_account_id
ebay_de_account_code
ebay_de_url
ebay_de_status
ebay_de_listing_price
ebay_de_listing_currency
ebay_de_last_synced_at
ebay_de_last_seen_at
ovoko_listing_count
ovoko_resolution_status
ovoko_listing_resolution_status
ovoko_listing_local_id
ovoko_external_listing_id
ovoko_external_offer_id
ovoko_marketplace_account_id
ovoko_account_code
ovoko_url
ovoko_status
ovoko_listing_price
ovoko_listing_currency
ovoko_last_synced_at
ovoko_last_seen_at
```

## Failure diagnostics and MySQL compatibility follow-up

The #1536 engine check used `pluck('engine')` against information_schema.tables.
MySQL 8.4 returns the unaliased label `ENGINE`; Laravel then raises ErrorException
when accessing `$row->engine`. The regression test reproduces this exact old
expression, and the fixed code uses `ENGINE AS source_engine`. This happens
before sample selection or capture file creation. This is a locally reproduced
code defect; without a production receipt/log it is not proof of which failure
the reported production request encountered.

The exact SET TRANSACTION / beginTransaction ordering is exercised against real,
isolated MySQL 8.4. A raw write in that transaction is rejected with error 1792.
The integration fixture is synthetic and has NULL relationships and no optional
tables. Tests require the disposable database name `fresh_export_test` and do not
connect to a production database.

Sample ranking now considers at most the first 100 eligible IDs in ascending
order, then selects at most five by the existing completeness score. The query
never ranks the entire product catalog. Capture remains limited to the selected
five IDs and their local relations. No broad data capture precedes selection.

Prepare no longer executes Git or depends on proc_open: source revision is read
from `GPSWISS_PRODUCT_EXPORT_SOURCE_COMMIT` configuration or local .git files via
PHP. In a ZIP deployment without revision metadata it is NULL, explicitly marked
source_commit_status=unavailable; it is never guessed. Configure the verified
release SHA when that metadata is required. No deploy helper is modified here.

Each mutating operation persists a unique `operation-{operation_id}.json` receipt
and the latest `operation.json`, with export_id, action, phase,
reason_code, backend_exception_class, safe_exception_category, current_table,
current_join, current_part_id, capture_processed, selected_products,
checkpoint_present, last_successful_phase, duration_ms and memory_peak_bytes.
Phases are persisted **before** risky calls. Read-only status polling returns
this receipt without rewriting it. Exceptions return a JSON receipt immediately;
UI displays it even if the operation failed before its normal response. The
receipt never stores exception messages, SQL/bindings, trace, tokens or payloads.
Serialization errors and common SQLSTATE/schema/permission/timeout categories
are classified without exposing their text. A shutdown callback reserves memory
for a best-effort fatal receipt; SIGKILL, worker loss or an unwritable filesystem
cannot guarantee a final receipt. A last `running` phase indicates interruption,
not success. If the private directory cannot be written, safe metadata falls back
to PHP's error log; durable artifact storage is then unavailable.

The deployment archive path has no .git, so #1536's source revision lookup was a
separate preparation failure mode. It is covered without invoking a subprocess.
Known engine compatibility and diagnostics gaps are fixed here; the historical
production incident remains unconfirmed without its existing log/checkpoint.
