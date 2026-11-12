# Single-file product export to WystawCzesc

Schema: `gpswiss-products-2`. Exactly one CSV is generated per export:
`czesci.csv`, `sprzedane.csv` or `do_wystawienia.csv`. One CSV record is one part,
including all associated data. `manifest.json` is the only other download.
No photo, listing, relationship or issues CSV is generated.

OwnerAdmin page: **Części → Eksport do WystawCzesc**, at
`GET /admin/tools/product-migration-export`. POST here accepts `action=prepare`,
`type=czesci|sprzedane|do_wystawienia`, `mode=sample|full`.
`GET /admin/tools/product-migration-export/{export}` reads owner-scoped status.
POST on that URL accepts `capture|tick|stop|resume`. Download uses GET with
`?download={selected-main-file.csv|manifest.json}`. Requests keep existing
read-only session/SQL middleware, authentication, OwnerAdmin checks and CSRF.
The old Migration Runner and its captures/photos/A/B/C are never accessed.

## Single-row JSON contract

All JSON cells contain valid JSON after parsing the semicolon-delimited CSV.
Arrays are `[]` when empty; missing related objects are CSV NULL (`\N`).
Identifiers/codes remain text, including within JSON where the capture normalized
them as text. HTML, Unicode and newlines are not truncated.

- `images_json`: ordered array of image objects, with image_id, original_name,
  source_path, sort_order, is_primary, role, source_type, mime_type, extension,
  import_order, copy_relative_path and complete sanitized source_metadata.
  This is the authoritative gallery. Order is primary first, then sort_order,
  image ID and path; import_order starts at 1. source_metadata retains alt text,
  source identifiers and stored presentation-variant paths. Code photos are
  included. image_count and primary_image_path are convenience fields. Paths,
  never basenames alone, identify copied files. No physical file is read/copied.
- `marketplace_listings_json`: array of all locally captured listings for this
  part, retaining each row's safe fields/source_metadata, local/external IDs,
  account identity, price/currency, status, URL and market resolution. All
  ambiguous DE/Ovoko offers remain here. Historical FR and Allegro rows can be
  retained here; there are no separate FR shortcut columns. No supplement is used.
- `issues_json`: array of objects `{part_id, reason_code, details}`. Existing
  reason codes are retained, including missing_car/category/images, orphan
  references, multiple_ebay_de_listings/multiple_ovoko_listings,
  ebay_market_conflict/unknown and missing marketplace URLs.
  `issues_count` is exactly its array length. There is no issues.csv.
- `car_json`, `category_json`, `storage_location_json`: complete sanitized local
  related records, or NULL, preserving fields beyond the flat convenience columns.
- `category_mappings_json`, `part_translations_json`,
  `category_translations_json`: the respective per-part related arrays that were
  previously exported in relacje_czesci.csv. No unrelated parts' data is embedded.
- `vehicle_snapshot`, `additional_part_codes`, `legacy_payload`, `review_metadata`
  and `source_part_json` retain their existing content and sanitization semantics.

Main CSV projects eBay DE and Ovoko only. Exactly one matching listing gives
shortcut values; zero/multiple or unresolved eBay signals leave individual values
NULL. The original DE detector is unchanged: explicit channel, account and
locally stored marketplaceId signals must agree; URL domains are not evidence.
A DE+FR pair still resolves the single DE listing. FR remains only in nested data.

Car identity is parts.car_id → cars.id; Ovoko ID is an additional identity.
Car generation/year/engine/gearbox/fuel map to model_variant/production_year/
engine_capacity_cm3 (cm³)/gearbox_type/fuel_type. No linked car yields NULL car
object and convenience fields. Parts prices and recorded listing prices remain
independent, with no conversion or fallback; parts.ebay_price is the PLN base.
Local Allegro shipping ID requires one associated account and one matching rate.
Store URLs still use configured Polish app.url plus the named storefront.product
route `/produkt/{slug}`, with local ID fallback. Legacy URL/slugs are preserved.
A constructed URL does not promise availability for a sold/hidden part.

## Capture, checkpoint and safety

Data acquisition is unchanged: new ID and fresh Query Builder capture each time,
in one MySQL REPEATABLE READ / READ ONLY transaction (InnoDB required), or a SQLite
read transaction in tests. No Eloquent save/observers, jobs, marketplace clients,
provider GET/WRITE or external resolver. Optional tables may be absent.
Części exports all parts; Sprzedane uses status=sold; Do wystawienia uses
needs_listing=true. Sample ranks at most the first 100 eligible IDs and captures
at most five selected parts and only their local relations. Full never adopts
sample data. Capture reads batches of 100 parts into a private JSONL file, sealed
only after the transaction succeeds. CSV projection reads at most 25 parts per
tick, with memory bounded by one captured part during projection.

Output offsets and counters are atomically checkpointed. Resume truncates any
uncommitted CSV tail and verifies the capture hash. Stop pauses between projection
ticks. Reload retains ID locally and reads server state. A live DB transaction
cannot span requests: interrupted capture requires a new export. External worker
kills/timeouts cannot guarantee a final receipt. Production timing is not asserted.

Version 1 exports are not resumed or silently upgraded to the new column layout.
Their files/checkpoints are left intact; prepare a new export for version 2.
Version 1 helper CSV downloads are no longer exposed by this exporter.

The manifest contains export identity, capture times, source_commit/status,
schema_version, record/image/listing/issue counts, capture hash, the single CSV's
SHA256/bytes and exact ordered columns. Revision comes from configuration
GPSWISS_PRODUCT_EXPORT_SOURCE_COMMIT or PHP-local .git metadata, without a
subprocess. Missing revision metadata is explicitly unavailable, never guessed.

CSV uses UTF-8 BOM, `;`, double-quote escaping, JSON for arrays/objects, `\N` for
NULL and doubled leading backslashes for literal values. Values beginning with
whitespace/control characters then =,+,@,- receive an apostrophe formula guard.
Importers must reverse these conventions and treat IDs/codes as text. Excel's
automatic type inference can destroy leading zeros.

Private artifacts use directories 0700/files 0600; responses disable caching.
Downloads verify hashes. Auth, owner isolation and filename allowlists apply.
Per-operation safe diagnostics persist before risky phases and on exceptions in
operation-{operation_id}.json and latest operation.json. Status polls never
rewrite them. Receipts contain only export/action/phase, reason/category,
exception class, table/relation/part ID, counts, checkpoint presence, last good
phase, duration and peak memory. No SQL, messages, traces, credentials or payloads.
MySQL ENGINE metadata keeps the explicit source_engine alias fixed in #1537.

## Verification

Tests cover all three datasets in sample/full modes; exactly one CSV on disk and
in downloads; JSON roundtrip, image order/metadata, complete reference objects,
ambiguous listings retained without shortcuts, FR confined to nested data,
embedded issues, manifest hash, fresh capture, Stop/Resume, uncommitted tail
rollback, safe diagnostics, auth, CSV encoding and SELECT-only/zero-provider scope.
The MySQL test runs only on a disposable database named fresh_export_test.
Chromium tests use intercepted requests; no production export or deployment.

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
marketplace_listings_json
issues_count
issues_json
car_json
category_json
storage_location_json
category_mappings_json
part_translations_json
category_translations_json
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
